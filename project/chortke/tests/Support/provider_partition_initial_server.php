<?php
$signal=getenv('CHAOS_PROVIDER_SIGNAL');if(is_string($signal)&&$signal!=='')file_put_contents($signal,'first-attempt');
header('Content-Type: application/json');http_response_code(503);echo json_encode(['error'=>'partition_started']);
