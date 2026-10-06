<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One page of a scanned upload, kept while its text waits for the AI pass.
 *
 * @property int $id
 * @property int $book_file_id
 * @property int $page
 * @property string|null $path
 * @property string|null $text
 * @property Carbon|null $processed_at
 */
class BookScanPage extends Model
{
    public $timestamps = false;

    protected $fillable = ['page', 'path', 'text', 'processed_at'];

    protected function casts(): array
    {
        return ['processed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<BookFile, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(BookFile::class, 'book_file_id');
    }
}
