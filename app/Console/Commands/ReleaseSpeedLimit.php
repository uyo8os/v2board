<?php

namespace App\Console\Commands;

use App\Services\SpeedLimitService;
use Illuminate\Console\Command;

class ReleaseSpeedLimit extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'speedlimit:release';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '动态限速自动解除到期记录';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        (new SpeedLimitService())->releaseExpired();
    }
}
