<?php
// Isolated app only. All HTTP fixture writes, origin receipts and counters roll back.
$loader = require getcwd().'/vendor/autoload.php';
$loader->setPsr4('App\\', getcwd().'/app');
$classMap = [];
foreach ($loader->getClassMap() as $class => $file) {
    if (str_starts_with($class, 'App\\')) {
        $staged = getcwd().'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
        if (is_file($staged)) $classMap[$class] = $staged;
    }
}
$loader->addClassMap($classMap);
$app = require getcwd().'/bootstrap/app.php';
$app->instance('request', Illuminate\Http\Request::create('https://www.exploredavao.com/plan'));
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class); $kernel->bootstrap();
$app->instance('env','testing');
config(['session.driver'=>'array','cache.default'=>'array','view.compiled'=>getcwd().'/storage/framework/views']);
$checks=0;
$check=function($ok,$message)use(&$checks){if(!$ok)throw new RuntimeException($message);echo "PASS: $message\n";$checks++;};
$check(str_starts_with((new ReflectionClass(App\Support\VisitorType::class))->getFileName(),getcwd().'/app/'),'Checks load staged application code');
$check(App\Support\Features::tripLocation(),'Origin flow is enabled for these checks');
$v=App\Support\VisitorType::class;
foreach(App\Support\Geo\PhProvinces::BY_REGION['Region XI (Davao Region)'] as $province) $check($v::hasDavaoOrigin('PH',$province),'Resident eligibility accepts '.$province);
$check(!$v::hasDavaoOrigin('AR','Davao del Sur') && !$v::hasDavaoOrigin('PH','Cebu') && !$v::hasDavaoOrigin('PH','') && !$v::hasDavaoOrigin(null,null),'Foreign, other-province and incomplete origins are not local');
$check($v::forDisplay('Regular / Local','AR',null)==='Returning Visitor','Legacy foreign regular visitors display as returning');
$check($v::forDisplay('Regular / Local','PH','Davao del Sur')===$v::RESIDENT,'Legacy local trip displays the explicit resident choice');
$check($v::forDisplay($v::RESIDENT,'PH','Cebu')===null,'Stale resident selection outside Region XI requires a new answer');
$check($v::options(false)[2]==='Regular / Local','Legacy feature-off choices remain compatible');
foreach([0,0.35,1] as $share) $check(App\Services\Recommendation\PreferenceFit::visitor($share,$v::RESIDENT)===App\Services\Recommendation\PreferenceFit::visitor($share,$v::LEGACY_LOCAL),'Resident recommendation score preserves the previous local weighting at '.$share);
$check(in_array($v::RESIDENT,App\Http\Controllers\ExitSurveyController::VISITOR_TYPES,true),'Admin survey filters accept the new resident value');
$context=App\Services\ExitSurveyTripContext::from(new App\Models\TouristPreference(['visitor_type'=>$v::RESIDENT,'visitor_origin_country_code'=>'PH','visitor_origin_province'=>'Davao del Sur','visitor_origin_source'=>'chosen']));
$check(($context['visitor_type']??null)===$v::RESIDENT && ($context['residency_type']??null)==='Local Resident','Exit surveys reuse the new resident answer');
$app->instance(App\Services\Recommendation\ItineraryGenerationService::class,new class extends App\Services\Recommendation\ItineraryGenerationService {
    public function __construct() {}
    public function generate(App\Models\TouristPreference $preference,?float $originLat=null,?float $originLng=null,array $swaps=[]):App\Models\Itinerary
    { return App\Models\Itinerary::create(['preference_id'=>$preference->id,'total_days'=>1,'est_budget_total'=>0]); }
});
$request=function(string $method,array $data=[])use($app,$kernel){
    $app['session']->driver()->flush();
    $r=Illuminate\Http\Request::create('https://www.exploredavao.com/plan',$method,$data,[],[],['HTTP_ACCEPT'=>'application/json']);
    $app->instance('request',$r);return $kernel->handle($r);
};
$base=['travel_type'=>'Solo','travel_purpose'=>'Leisure','budget'=>'Mid-range','accommodation_pref'=>'Hotel','distance_pref'=>'near',
    'start_at'=>now('Asia/Manila')->addDays(2)->format('Y-m-d').'T09:00','end_at'=>now('Asia/Manila')->addDays(2)->format('Y-m-d').'T17:00',
    'origin_label'=>'Davao City Hall','origin_lat'=>7.064,'origin_lng'=>125.609,'activities'=>['Food Tourism']];
Illuminate\Support\Facades\DB::beginTransaction();
try {
    $before=App\Models\TouristPreference::count();
    foreach([['AR','',$v::RESIDENT],['AR','Davao del Sur',$v::LEGACY_LOCAL],['PH','Cebu',$v::RESIDENT]] as [$country,$province,$type]){
        $response=$request('POST',$base+['visitor_type'=>$type,'visitor_origin_country'=>$country,'visitor_origin_province'=>$province]);
        $json=json_decode($response->getContent(),true);
        $check($response->getStatusCode()===422 && isset($json['errors']['visitor_type']),'HTTP rejects forged local type for '.$country.'/'.$province);
    }
    $check(App\Models\TouristPreference::count()===$before,'Invalid local submissions write no trip records');
    foreach([['AR','',$v::FIRST,$v::FIRST],['PH','Cebu',$v::RETURNING,$v::RETURNING],['PH','Davao del Sur',$v::RESIDENT,$v::RESIDENT],['PH','Davao del Norte',$v::LEGACY_LOCAL,$v::RESIDENT]] as [$country,$province,$type,$expected]){
        $response=$request('POST',$base+['visitor_type'=>$type,'visitor_origin_country'=>$country,'visitor_origin_province'=>$province]);
        if($response->getStatusCode()!==200)throw new RuntimeException($response->getContent());
        $saved=App\Models\TouristPreference::latest('id')->firstOrFail();
        $check($saved->visitor_type===$expected && $saved->visitor_origin_country_code===$country,'HTTP saves '.$country.'/'.$type.' with the correct origin and canonical type');
    }
    $response=$request('GET');
    $check($response->getStatusCode()===200,'Actual planner page renders with the new controls');
    file_put_contents(getcwd().'/qa-plan.html',$response->getContent());
    $dom=new DOMDocument();@$dom->loadHTML($response->getContent());$xp=new DOMXPath($dom);
    $check($xp->query('//*[@data-resident-choice and @disabled]')->length===1 && $xp->query('//*[@data-resident-option and @hidden]')->length===1,'Unanswered-origin server render hides and disables the resident choice');
    $check(str_contains($response->getContent(),'I live in Davao Region') && !str_contains($response->getContent(),'>Regular / Local<'),'Planner uses clear resident wording');
} finally { Illuminate\Support\Facades\DB::rollBack(); }
echo "$checks visitor-origin checks passed; HTTP fixtures and origin aggregates rolled back. Scheduler stubbed for persistence checks.\n";
