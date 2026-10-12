<?php

declare(strict_types=1);

namespace Plazorax\SemanticSearch\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Model;
use Plazorax\SemanticSearch\Contracts\Searchable;
use Plazorax\SemanticSearch\Jobs\IndexModel;
use Plazorax\SemanticSearch\SemanticSearchManager;

final class IndexCommand extends Command
{
    protected $signature = 'semantic-search:index
        {model : Model class, either fully qualified or a class name under App\Models}
        {--chunk=100 : Number of models loaded per database query}
        {--queue : Dispatch one queued job per model instead of indexing inline}
        {--fresh : Remove every stored chunk for the model type before indexing}';

    protected $description = 'Chunk, embed and store every record of a searchable model';

    public function handle(SemanticSearchManager $search, Dispatcher $bus, Config $config): int
    {
        $name = $this->argument('model');
        $modelClass = is_string($name) ? $this->resolveModelClass($name) : null;

        if ($modelClass === null) {
            $this->components->error(sprintf(
                'Model [%s] was not found or does not implement %s.', is_string($name) ? $name : '', Searchable::class
            ));

            return self::FAILURE;
        }

        $chunk = (int) $this->option('chunk');

        if ($chunk < 1) {
            $this->components->error('The --chunk option must be a positive integer.');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $search->flush($modelClass);
        }

        $queue = (bool) $this->option('queue');
        $query = $modelClass::query();
        $total = $query->count();
        $chunksStored = 0;

        $this->components->info(sprintf('%s %d [%s] records.', $queue ? 'Queueing' : 'Indexing', $total, $modelClass));

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById($chunk, function ($models) use ($search, $bus, $config, $queue, &$chunksStored, $bar): void {
            foreach ($models as $model) {
                /** @var Model&Searchable $model */
                if ($queue) {
                    $bus->dispatch(
                        (new IndexModel($model))
                            ->onConnection($config->get('semantic-search.queue.connection'))
                            ->onQueue($config->get('semantic-search.queue.queue'))
                    );
                } else {
                    $chunksStored += $search->index($model);
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->components->info($queue
            ? sprintf('Dispatched %d indexing jobs.', $total)
            : sprintf('Indexed %d records into %d chunks.', $total, $chunksStored));

        return self::SUCCESS;
    }

    /**
     * @return class-string<Model&Searchable>|null
     */
    private function resolveModelClass(string $name): ?string
    {
        $name = ltrim(str_replace('/', '\\', $name), '\\');

        foreach ([$name, 'App\\Models\\'.$name] as $candidate) {
            if (is_subclass_of($candidate, Model::class) && is_subclass_of($candidate, Searchable::class)) {
                return $candidate;
            }
        }

        return null;
    }
}
