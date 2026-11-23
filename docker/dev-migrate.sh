#!/bin/sh
# Runs as www-data inside the dev ownCloud.online container: on every
# `make dev-up` (dev-seed.sh), on `make dev-migrate` and before every e2e run
# (global setup). The mounted code may carry migrations and background jobs
# its version number does not announce yet; ownCloud.online applies those
# only when the version goes up, so a stack kept from an older checkout
# catches up here. Safe to repeat.
set -eu

cd /var/www/html
php occ migrations:migrate oco_synaplan >/dev/null

php -r 'require "/var/www/html/lib/base.php";
  $jobs = \OC::$server->getJobList();
  $info = \OC::$server->getAppManager()->getAppInfo("oco_synaplan") ?: [];
  foreach ($info["background-jobs"] ?? [] as $job) {
    if (!$jobs->has($job, null)) {
      $jobs->add($job);
      fwrite(STDERR, "Registered $job\n");
    }
  }'
