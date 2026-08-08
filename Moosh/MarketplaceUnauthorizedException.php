<?php
/**
 * Thrown when a download from marketplace.moodle.com fails specifically
 * with HTTP 401 (`{"code":401,"message":"Not privileged to request the
 * resource."}`) - ie. the plugin exists in plugins.json but its zip isn't
 * downloadable with the currently configured Marketplace token, typically
 * because it's a subscription-only plugin the token isn't entitled to.
 *
 * Kept as a distinct type (rather than a plain \RuntimeException) so
 * callers - namely PluginListUpdate - can tell "not privileged" apart from
 * any other download failure (network error, bad URL, 5xx, ...) and react
 * to it differently: skip the plugin with a warning instead of treating it
 * as a hard error.
 *
 * Deliberately lives under Moosh/ (like PluginCache/PluginChecksum), NOT
 * under Moosh/Command/ - moosh_load_all_commands() globs every
 * Command/<version>/*\/*.php file and instantiates it as a subcommand, so a
 * plain class (let alone an exception) placed there breaks with a fatal
 * "Call to undefined method ...::getName()".
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh;

class MarketplaceUnauthorizedException extends \RuntimeException
{
}
