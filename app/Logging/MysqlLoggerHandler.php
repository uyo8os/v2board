<?php
namespace App\Logging;

use Illuminate\Support\Facades\Log;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use App\Models\Log as LogModel;

class MysqlLoggerHandler extends AbstractProcessingHandler
{
    public function __construct($level = Logger::DEBUG, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
        $this->pushProcessor(new SafeLogProcessor());
    }

    protected function write(LogRecord $record): void
    {
        $record = $record->toArray();
        try{
            $method = request()->getMethod();
            $method = in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], true) ? $method : '';
            $log = [
                'title' => $record['message'],
                'level' => $record['level_name'],
                'host' => null,
                'uri' => $record['context']['action'] ?? '',
                'method' => $method,
                'ip' => request()->getClientIp(),
                'data' => '{}',
                'context' => json_encode($record['context']),
                'created_at' => $record['datetime']->getTimestamp(),
                'updated_at' => $record['datetime']->getTimestamp(),
            ];

            LogModel::insert(
                $log
            );
        }catch (\Throwable $e){
            Log::channel('daily')->error('System log database write failed');
        }
    }
}
