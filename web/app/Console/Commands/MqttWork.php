<?php

namespace App\Console\Commands;

use App\Models\StationCommand;
use App\Services\CommandService;
use App\Services\MessageIngestor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use Throwable;

class MqttWork extends Command
{
    protected $signature = 'station:mqtt';

    protected $description = 'Receive station messages and dispatch the durable command outbox';

    public function handle(MessageIngestor $ingestor, CommandService $commands): int
    {
        $lock = fopen(storage_path('framework/mqtt-worker.lock'), 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            $this->error('Worker lain masih aktif.');

            return self::FAILURE;
        }
        try {
            while (true) {
                try {
                    $config = config('station.mqtt');
                    $client = new MqttClient($config['host'], $config['port'], 'petstation-backend', MqttClient::MQTT_3_1_1);
                    $settings = (new ConnectionSettings)->setUsername($config['username'])->setPassword($config['password'])->setConnectTimeout(5)->setKeepAliveInterval(10)->setUseTls($config['tls']);
                    if ($config['ca_file']) {
                        $settings = $settings->setTlsCertificateAuthorityFile($config['ca_file']);
                    }
                    $client->connect($settings, true);
                    foreach (['telemetry', 'state', 'result'] as $kind) {
                        $client->subscribe('petstation/+/'.$kind, function (string $topic, string $message, bool $retained) use ($client, $ingestor): void {
                            try {
                                if (strlen($message) > 65536) {
                                    throw new \RuntimeException('Payload exceeds 64 KiB');
                                }
                                $ack = $ingestor->ingest($topic, $message, $retained);
                                if ($ack) {
                                    $client->publish('petstation/'.$ack['device_id'].'/ack', json_encode($ack, JSON_THROW_ON_ERROR), 1, false);
                                }
                            } catch (Throwable $error) {
                                Log::warning('MQTT message rejected', ['topic' => $topic, 'error' => $error->getMessage()]);
                            }
                        }, 1);
                    }
                    $lastTick = 0;
                    $client->registerLoopEventHandler(function (MqttClient $client) use ($commands, &$lastTick): void {
                        if (microtime(true) - $lastTick < 1) {
                            return;
                        }
                        $lastTick = microtime(true);
                        Cache::put('mqtt-worker-online', true, 8);
                        $commands->expire();
                        foreach (StationCommand::query()->with('station')->where('status', 'pending')->whereNull('published_at')->where('expires_at', '>', now())->limit(20)->get() as $command) {
                            $client->publish('petstation/'.$command->station->device_id.'/command', json_encode(['schema_version' => 1, 'device_id' => $command->station->device_id, 'command_id' => $command->id, 'action' => $command->action, 'parameters' => $command->parameters, 'expires_at' => $command->expires_at->toIso8601String()], JSON_THROW_ON_ERROR), 1, false);
                            $command->update(['published_at' => now()]);
                        }
                    });
                    $this->info('Worker MQTT tersambung.');
                    $client->loop(true);
                } catch (Throwable $error) {
                    Cache::forget('mqtt-worker-online');
                    $this->warn('Koneksi MQTT terputus: '.$error->getMessage());
                    $commands->expire();
                    sleep(3);
                }
            }
        } finally {
            Cache::forget('mqtt-worker-online');
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
