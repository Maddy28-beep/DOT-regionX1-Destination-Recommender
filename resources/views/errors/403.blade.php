@include('errors.minimal', [
    'code' => '403',
    'icon' => 'lock',
    'title' => 'Access denied',
    'message' => "You don't have permission to view this page. If you think this is a mistake, try signing in with the right account.",
])
