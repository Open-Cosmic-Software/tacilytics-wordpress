<?php
// TEST ONLY: the local test server runs over http on a private docker IP.
add_filter( 'tacilytics_allow_http_server', '__return_true' );
add_filter( 'tacilytics_allow_private_server', '__return_true' );
// TEST ONLY: replacement-string trap (Tyto): a value containing $0 and \1 must arrive literally.
add_filter( 'tacilytics_script_attributes', static function ( $a ) { $a['data-taci-test'] = 'a$0b\\1c'; return $a; } );
