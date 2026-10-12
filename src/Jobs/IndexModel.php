<?php

declare(strict_types=1);

namespace Plazorax\SemanticSearch\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Plazorax\SemanticSearch\Contracts\Searchable;
use Plazorax\SemanticSearch\SemanticSearchManager;

/**
 * Indexes one model on a queue worker.
 */
final class IndexModel implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Skip the job silently if the model was deleted before it ran. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public Model&Searchable $model) {}

    public function handle(SemanticSearchManager $search): void
    {
        $search->index($this->model);
    }
}
