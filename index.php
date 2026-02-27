<?php
// Silence is golden. Direct access is not.
http_response_code( 403 );
header( 'HTTP/1.1 403 Forbidden' );
exit( 'Forbidden' );
