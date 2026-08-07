<?php
/**
 * Router script for `php -S 127.0.0.1:<port> tests/fixtures/marketplace-401-server.php`,
 * used by PluginListUpdateTest to exercise the real HTTP-401-detection code
 * path (isMarketplaceUnauthorizedResponse() / downloadPluginZip()) against
 * an actual HTTP response, without touching marketplace.moodle.com or the
 * network at all - everything happens over the loopback interface.
 *
 * Every request gets the exact status code and body documented for
 * subscription-only plugins on marketplace.moodle.com:
 *   HTTP/1.1 401 Unauthorized
 *   {"code":401,"message":"Not privileged to request the resource."}
 */

http_response_code(401);
header('Content-Type: application/json');
echo json_encode(array('code' => 401, 'message' => 'Not privileged to request the resource.'));
