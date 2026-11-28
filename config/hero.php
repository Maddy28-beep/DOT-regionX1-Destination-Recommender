<?php

/*
 * Where the landing page looks for its hero picture and footage. The real locations are the defaults; a test points them at
 * a temporary folder instead, so that no test ever has to move or delete a real file under public/.
 */
return [
    // Optional hero footage: hero.mp4, hero.webm and hero-poster.jpg (served from public/video).
    'video_directory' => public_path('video'),

    // The Mount Apo photograph. When this file exists it is the hero and the footage is not drawn.
    'photo_path' => public_path('images/mt-apo-kapatagan-hero.jpg'),
];
