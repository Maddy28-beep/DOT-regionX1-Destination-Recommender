// node deploy/check-visitor-local-origin.cjs <jsdom package path> <staged public planner html>
const fs=require('node:fs'), assert=require('node:assert/strict');
const {JSDOM}=require(process.argv[2]);
const html=fs.readFileSync(process.argv[3],'utf8');
const code=fs.readFileSync('public/js/plan-visitor-type.js','utf8');
const wizard=fs.readFileSync('public/js/plan-wizard.js','utf8');
let checks=0;const check=(ok,msg)=>{assert.ok(ok,msg);checks++;};
function setup(country='',province=''){
 const dom=new JSDOM(html,{url:'https://www.exploredavao.com/plan',runScripts:'outside-only',pretendToBeVisual:true});
 const w=dom.window,d=w.document,form=d.getElementById('planPreferencesForm');
 w.HTMLElement.prototype.scrollIntoView=function(){};w.matchMedia=()=>({matches:true,addEventListener(){}});
 const c=form.elements.namedItem('visitor_origin_country'),p=form.elements.namedItem('visitor_origin_province');c.value=country;p.value=province;
 w.eval(code);return {dom,w,d,form,c,p,resident:d.querySelector('[data-resident-choice]'),card:d.querySelector('[data-resident-option]')};
}
const x=setup('AR');
check(x.card.hidden && x.resident.disabled,'Argentina hides and disables resident choice');
check([...x.form.querySelectorAll('[name="visitor_type"]')].filter(r=>!r.disabled).length===2,'Foreign visitors have exactly two enabled choices');
check(x.d.querySelector('[data-visitor-type-hint]').textContent.includes('Returning Visitor'),'Frequent visitors are directed to Returning');
x.c.value='PH';x.p.value='Cebu';x.p.dispatchEvent(new x.w.Event('change',{bubbles:true}));
check(x.card.hidden && x.resident.disabled,'Philippine origin outside Region XI also has two choices');
for(const province of ['Davao de Oro','Davao del Norte','Davao del Sur','Davao Occidental','Davao Oriental']){
 x.p.value=province;x.p.dispatchEvent(new x.w.Event('change',{bubbles:true}));
 check(!x.card.hidden && !x.resident.disabled,'Resident choice available for '+province);
}
check(x.card.textContent.includes('I live in Davao Region'),'Resident wording is explicit');
x.resident.checked=true;x.resident.dispatchEvent(new x.w.Event('change',{bubbles:true}));
x.c.value='AR';x.c.dispatchEvent(new x.w.Event('input',{bubbles:true}));
check(!x.resident.checked && x.resident.disabled && x.card.hidden,'Changing away from local origin clears a resident answer');
check(new x.w.FormData(x.form).get('visitor_type')===null,'Stale local value cannot enter the submitted form data');
check(x.d.querySelector('[data-visitor-type-grid]').style.getPropertyValue('--visitor-columns')==='2','Nonlocal grid uses two columns');
x.c.value='PH';x.p.value='Davao del Sur';x.p.dispatchEvent(new x.w.Event('change',{bubbles:true}));
check(x.d.querySelector('[data-visitor-type-grid]').style.getPropertyValue('--visitor-columns')==='3','Local grid uses three columns');
const lat=x.form.elements.namedItem('origin_lat');lat.value='7.064';x.c.value='JP';x.c.dispatchEvent(new x.w.Event('change',{bubbles:true}));
check(lat.value==='7.064','Visitor-type updates never change the pick-up coordinates');
x.resident.checked=true;x.d.querySelector('[data-visitor-types]').dispatchEvent(new x.w.Event('change',{bubbles:true}));
check(!x.resident.checked,'A script-restored invalid resident choice is cleared');
x.dom.window.close();
function draft(country,province,oldValue,expected){
 const s=setup(country,province),root=s.d.getElementById('planWizard');root.removeAttribute('data-origin-pending');
 const names=JSON.parse(wizard.match(/const draftNames = (\[[^\n]+\]);/)[1].replaceAll("'",'"'));
 const values=Object.fromEntries(names.map(n=>[n,[]]));values.visitor_type=[oldValue];values.travel_days=['1'];values.budget=['Mid-range'];
 s.w.localStorage.setItem('dvo-plan-draft-v2:'+root.dataset.draftOwner,JSON.stringify({savedAt:Date.now(),step:0,values}));
 s.w.eval(wizard);s.d.getElementById('planResumeContinue').click();
 check(s.form.querySelector('[name="visitor_type"]:checked')?.value===expected,'Legacy/saved draft '+oldValue+' in '+country+'/'+province+' restores correctly');
 s.dom.window.close();
}
draft('AR','','Regular / Local','Returning Visitor');
draft('PH','Davao del Sur','Regular / Local','Davao Region Resident');
draft('PH','Cebu','Davao Region Resident',undefined);
console.log(checks+' visitor-choice DOM checks passed.');
