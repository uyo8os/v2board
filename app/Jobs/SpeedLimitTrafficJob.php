<?php

namespace App\Jobs;

use App\Services\SpeedLimitService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SpeedLimitTrafficJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $data;
    protected $server;
    protected $protocol;

    public $tries = 3;
    public $timeout = 30;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(array $data, array $server, $protocol)
    {
        $this->onQueue('speed_limit');
        $this->data = $data;
        $this->server = $server;
        $this->protocol = $protocol;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $speedLimitService = new SpeedLimitService();
        $rate = isset($this->server['rate']) ? (float)$this->server['rate'] : 1.0;
        $speedLimitService->handleNodeTraffic(
            $this->protocol,
            $this->server['id'],
            $this->server['name'] ?? '',
            $this->data,
            $rate
        );
    }
}
