<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The searchable text of a book's pages (see Catalog).
 *
 * @property int $book_id
 * @property string $text
 */
class BookText extends Model
{
    protected $primaryKey = 'book_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['book_id', 'text'];

    /**
     * @return BelongsTo<Book, $this>
     */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
