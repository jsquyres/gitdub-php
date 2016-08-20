<?php
#
# Copyright (c) 2016 Jeffrey M. Squyres.  All rights reserved.
#
# This is a simple re-spin of Matthias Vallentin's "gitdub", but in a
# pure PHP form (the excellent original "gitdub" project is a Ruby /
# Sinatra daemon: see https://github.com/mavam/gitdub).
#
# See the README.md for more details.
#
#########################################################################
#
# Redistribution and use in source and binary forms, with or without
# modification, are permitted provided that the following conditions are
# met:
#
# - Redistributions of source code must retain the above copyright
#   notice, this list of conditions and the following disclaimer.
#
# - Redistributions in binary form must reproduce the above copyright
#   notice, this list of conditions and the following disclaimer listed
#   in this license in the documentation and/or other materials
#   provided with the distribution.
#
# - Neither the name of the copyright holders nor the names of its
#   contributors may be used to endorse or promote products derived from
#   this software without specific prior written permission.
#
# The copyright holders provide no reassurances that the source code
# provided does not infringe any patent, copyright, or any other
# intellectual property rights of third parties.  The copyright holders
# disclaim any liability to any recipient for claims brought against
# recipient by any third party for infringement of that parties
# intellectual property rights.
#
# THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
# "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
# LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
# A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
# OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
# SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
# LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
# DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
# THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
# (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
# OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
#

##############################################################################
##############################################################################
# Fill in your configuration values in this routine
##############################################################################
##############################################################################

if (!is_file("config.inc")) {
    my_die("Cannot find gitdub.php's config.inc file.");
}
require_once "config.inc";

##############################################################################
##############################################################################
# You should not need to change below this line
##############################################################################
##############################################################################

# Functions

function my_die($msg)
{
    # Die with a non-200 error code
    http_response_code(400);

    die($msg);
}

function do_cmd($cmd)
{
    system($cmd, $ret);

    # This is somewhat of a hack (checking the return value directly),
    # but pcntl_*() functions are new / may not be available in your
    # version of PHP.
    if ($ret != 0 && $ret < 128) {
        my_die("Command failed (in dir: " . getcwd() . ", ret=$ret): $cmd\n");
    }
}

function debug($config, $str)
{
    if (isset($config["debug"]) && $config["debug"]) {
        print($str);
    }
}

function check_for_script($config)
{
    # Sanity check
    if (! is_file($config["post-receive-email"])) {
        my_die("Cannot find " . $config["post-receive-email"]);
    }
}

function check_for_allowed_sources($config)
{
    global $config;

    if (isset($config["allowed_sources"]) &&
        count($config["allowed_sources"] > 0)) {
	if (isset($_SERVER["HTTP_X_REAL_IP"])) {
            $source = ip2long($_SERVER["HTTP_X_REAL_IP"]);
        } else if (isset($_SERVER["REMOTE_ADDR"])) {
            $source = ip2long($_SERVER["REMOTE_ADDR"]);
        } else {
            # This will not match anything
            $source = 0;
        }

        $happy = 0;
        foreach ($config["allowed_sources"] as $cidr) {
            $parts = explode('/', $cidr);
            $value = ip2long($parts[0]);
            $mask = (pow(2, 33) - 1) - (pow(2, $parts[1] + 1) - 1);

            if (($value & $mask) == ($source & $mask)) {
                $happy = 1;
            }
        }
        if (!$happy) {
            my_die("Discarding request from disallowed IP address (" .
            $_SERVER["HTTP_X_REAL_IP"] . ")\n");
        }
    }
}

function check_for_non_empty_payload()
{
    # Ensure we got a non-empty payload
    if (!isset($_POST["payload"])) {
        my_die("Received POST request with empty payload\n");
    }
}

function parse_json()
{
    # Parse the JSON
    $json = json_decode($_POST["payload"]);
    if (json_last_error() != JSON_ERROR_NONE) {
        my_die("Got invalid JSON\n");
    }

    # JMS debug
    #print_r($json);

    return $json;
}

function fill_opts_from_json($json)
{
    # If there is no "before" property, then it's just a github ping
    # and we can ignore it
    if (!isset($json->{"before"})) {
       print "Hello, Github ping!  I'm here!\n";
       exit(0);
    }

    $url = $json->{"repository"}->{"url"};
    $opts["link"] =
        "$url/compare/" . $json->{"before"} . "..." . $json->{"after"};

    $opts["repo"] = $json->{"repository"}->{"full_name"};
    $opts["url"] = $json->{"repository"}->{"url"};

    $opts["before"] = $json->{"before"};
    $opts["after"] = $json->{"after"};
    $opts["refname"] = $json->{"ref"};

    return $opts;
}

function fill_opts_from_keys($config, $opts, $arr)
{
    # Deep copy the keys/values into the already-existing $opts
    # array
    foreach ($arr as $k => $v) {
        $opts[$k] = $v;
    }

    # Was the URL set?
    if (!isset($opts["uri"]) && isset($config["url"])) {
        $opts["uri"] = $config["url"];
    }

    return $opts;
}

function determine_remote($config, $opts)
{
    if (!isset($opts["protocol"])) {
        if (isset($config["protocol"])) {
            $opts["protocol"] = $config["protocol"];
        } else {
            $opts["protocol"] = "git";
        }
    }

    $repo = $opts["repo"];
    switch($opts["protocol"]) {
    case "git":
        $remote = "git://github.com/$repo.git";
        break;
    case "ssh":
        $remote = "git@github.com/$repo.git";
        break;
    case "https":
        $remote = "https://github.com/$repo.git";
        break;
    default:
        my_die("Unknown protocol for $key github repo: " . $value["protocol"] . "\n");
        break;
    }

    return $remote;
}

function get_clone($config, $opts, $remote)
{
    debug($config, "CWD is: " . getcwd() . "\n");

    $repo = $opts["repo"];
    $dir = getcwd() . "/" . $config["state_dir"] . "/$repo";
    if (!is_dir($dir)) {
        debug($config, "Need to make dir! $dir\n");
        if (!mkdir($dir, 0755, true)) {
            my_die("mkdir of $dir failed\n");
        }
        # JMS debug
        #do_cmd("ls -ld $dir\n");

        $cmd = "git clone --bare " . escapeshellarg($remote) . " " .
            escapeshellarg($dir);
        do_cmd($cmd, $ret);
    } else {
        debug($config, "Already have dir: $dir\n");
    }

    # Gitdub checks for empty directories here; is that really necessary?

    return $dir;
}

function get_value($config, $opts, $key)
{
    if (isset($opts[$key])) {
        return $opts[$key];
    } else if (isset($config[$key])) {
        return $config[$key];
    }

    return null;
}

function set_clone_config($config, $opts, $dir)
{
    if (!chdir($dir)) {
        my_die("Failed to chdir to $dir");
    }
    $cfg["hooks.envelopesender"] = get_value($config, $opts, "from");
    $cfg["hooks.emailprefix"] = get_value($config, $opts, "subject");
    $to = get_value($config, $opts, "to");
    if (is_array($to)) {
        $cfg["hooks.mailinglist"] = join(",", $to);
    } else {
        $cfg["hooks.mailinglist"] = $to;
    }

    $url = $opts["url"];
    $cfg["hooks.showrev"] = "t=%s; printf '$url/commit/%%s' \$t; echo; echo; git show -C \$t; echo; echo";
    $cfg["hooks.emailmaxlines"] = "5000";
    $cfg["hooks.diffopts"] = '--stat --summary --find-copies-harder';

    foreach ($cfg as $k => $v) {
        if (isset($v)) {
            $cmd = "git config --local $k " . escapeshellarg($v);
            debug($config, "Running config cmd: $cmd");
            do_cmd($cmd, $ret);
        }
    }

    # JMS debug
    #do_cmd("cat config");

    # Overwrite the default "description" file
    $repo = $opts["repo"];
    $handle = fopen("description", "w");
    fwrite($handle, "$repo\n");
    fclose($handle);
}

function notify($config, $opts, $dir)
{
    $cmd = 'git fetch origin +refs/heads/*:refs/heads/*';
    do_cmd($cmd);

    $oldrev = $opts["before"];
    $newrev = $opts["after"];
    $refname = $opts["refname"];

    $descriptors = array(
        0 => array("pipe", "r"),
        1 => array("pipe", "w"),
        2 => array("pipe", "w")
    );
    $cmd = $config["post-receive-email"];
    $process = proc_open($cmd, $descriptors, $pipes, NULL, NULL);
    if (is_resource($process)) {
        debug($config, "proc_open WRITING: $oldrev $newrev $refname\n");
        if (!fwrite($pipes[0], "$oldrev $newrev $refname\n")) {
            my_die("fwrite to $cmd failed!\n");
        }

        # Sleep a second to let the process run, otherwise a non-blocking
        # read on the pipe will get nothing back
        sleep(1);

        stream_set_blocking($pipes[1], FALSE);
        $stdout = stream_get_contents($pipes[1]);
        debug($config, "STDOUT: $stdout\n");

        stream_set_blocking($pipes[2], FALSE);
        $stderr = stream_get_contents($pipes[2]);
        debug($config, "STDERR: $stderr\n");

        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $ret = proc_close($process);
        debug($config, "Command ($cmd) return value: $ret\n");
    } else {
        my_die("Failed to proc_open\n");
    }

    # Put something in the Github webhook result output
    print("gitdub.php: post-receive-email successfully invoked\n");
}

function process($config, $opts, $key, $value)
{
    $opts = fill_opts_from_keys($config, $opts, $value);

    # Figure out the URL to use for the git repo
    $remote = determine_remote($config, $opts);

    # JMS debug
    #print_r($opts);

    # Clone the repo if we haven't already
    $dir = get_clone($config, $opts, $remote);

    # CD into the repo and set the config values
    set_clone_config($config, $opts, $dir);

    # Notify
    notify($config, $opts, $dir);

    # Happiness!  Exit.
    exit(1);
}

##############################################################################
# Main

# Verify that this is a POST
if (!isset($_POST) || count($_POST) == 0) {
    print("Use " . $_SERVER["REQUEST_URI"] . " as a WebHook URL in your Github repository settings.\n");
    exit(1);
}

# Read the config
$config = fill_config();

# Sanity checks
check_for_script($config);
check_for_allowed_sources($config);
check_for_non_empty_payload();

$json = parse_json();
$opts = fill_opts_from_json($json);

# Loop over all the repos in the config; see if this incoming request
# is from one we recognize
$repo = $opts["repo"] = $json->{"repository"}->{"full_name"};

foreach ($config["github"] as $key => $value) {
    debug($config, "Checking github id ($repo) against: $key<br />\n");
    if ($repo == $key) {
        debug($config, "Found match!\n");

        process($config, $opts, $key, $value);

        # process() will not return, but be paranoid anyway
        exit(0);
    }
}

# If we get here, it means we didn't find a repo match
my_die("no matching repository found for $repo");
