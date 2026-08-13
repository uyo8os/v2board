<?php

namespace App\Console\Commands;

use App\Services\SpeedLimitService;
use Illuminate\Console\Command;

class CleanSpeedLimitHistory extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'speedlimit:cleanHistory';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '清理动态限速历史记录（保留7天）';

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
        (new SpeedLimitService())->cleanupHistory(7);
    }
}
