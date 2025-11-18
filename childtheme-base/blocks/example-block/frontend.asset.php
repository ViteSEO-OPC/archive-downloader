<?php
return [
    'dependencies' => [],
    'version'      => filemtime( __DIR__ . '/frontend.js' ),  // cache-busts when file changes
];