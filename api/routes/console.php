<?php

// The relay's long-running processes are artisan commands, not scheduled tasks:
//   php artisan relay:dispatch   (scheduler service)
//   php artisan queue:work redis (worker service)
//
// TODO(retention): schedule a prune of old events/attempts here (respecting dedupe windows).
