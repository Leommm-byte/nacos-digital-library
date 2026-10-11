<?php

namespace App\Models;

use App\Enums\Level;
use App\Enums\Programme;
use App\Support\Classes\SchoolClass;
use Database\Factories\AnnouncementFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * News for everyone, or for one class (a programme, a level and, for HND,
 * the course): governors post to their own class, admins to anyone. The
 * message is Markdown (paragraphs, bold, lists, links); see html().
 *
 * @property int $id
 * @property string $title
 * @property string $body
 * @property Programme|null $programme
 * @property Level|null $level
 * @property string|null $arm
 * @property bool $is_published
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property int|null $created_by
 * @property Carbon $created_at
 */
class Announcement extends Model
{
    /** @use HasFactory<AnnouncementFactory> */
    use HasFactory;

    protected $fillable = ['title', 'body', 'programme', 'level', 'arm', 'is_published', 'starts_at', 'ends_at'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'programme' => null,
        'level' => null,
        'arm' => null,
    ];

    protected function casts(): array
    {
        return [
            'programme' => Programme::class,
            'level' => Level::class,
            'is_published' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * Published announcements inside their display window.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function live(Builder $query): void
    {
        $now = now();

        $query->where('is_published', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now));
    }

    /**
     * The ones a person may read: everyone's and their own class's (admins
     * read every class's). Graduates have no class, so only everyone's.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if (Gate::forUser($user)->allows('view-all-announcements')) {
            return;
        }

        $class = $user->schoolClass();

        $query->where(function (Builder $q) use ($class) {
            $q->whereNull('programme');

            if ($class !== null) {
                $q->orWhere(fn (Builder $mine) => $mine->where('programme', $class->programme)
                    ->where('level', $class->level)
                    ->when($class->arm === null, fn (Builder $b) => $b->whereNull('arm'), fn (Builder $b) => $b->where('arm', $class->arm)));
            }
        });
    }

    /**
     * Those for one class (what its governor manages).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function forClass(Builder $query, SchoolClass $class): void
    {
        $query->where('programme', $class->programme)
            ->where('level', $class->level)
            ->when($class->arm === null, fn (Builder $b) => $b->whereNull('arm'), fn (Builder $b) => $b->where('arm', $class->arm));
    }

    /**
     * The class it's for, or null when it's for everyone.
     */
    public function schoolClass(): ?SchoolClass
    {
        return SchoolClass::make($this->programme, $this->level, $this->arm);
    }

    public function isForEveryone(): bool
    {
        return $this->programme === null;
    }

    /** "Everyone" or "HND1 SWD Full-time" */
    public function audience(): string
    {
        return $this->schoolClass()?->label() ?? 'Everyone';
    }

    public function isLive(): bool
    {
        $now = now();

        return $this->is_published
            && ($this->starts_at === null || $this->starts_at->lte($now))
            && ($this->ends_at === null || $this->ends_at->gte($now));
    }

    /**
     * The day it's shown from (its first day, or when it was posted).
     */
    public function date(): Carbon
    {
        return $this->starts_at ?? $this->created_at;
    }

    /**
     * The message as safe HTML: paragraphs, **bold**, *italics*, lists,
     * headings, quotes and links. Raw HTML in the message is dropped, links
     * only go to http(s) and email, and images are left out (the
     * Content-Security-Policy blocks other sites' images anyway).
     */
    public function html(): string
    {
        return self::render($this->body);
    }

    public static function render(string $markdown): string
    {
        $html = Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 10,
        ]);

        // Images: keep the words, drop the picture.
        $html = (string) preg_replace('/<img\b[^>]*\balt="([^"]*)"[^>]*>/i', '$1', $html);
        $html = (string) preg_replace('/<img\b[^>]*>/i', '', $html);

        // Links open the page normally but pass on no rights or referrer.
        return (string) preg_replace('/<a href="/', '<a rel="nofollow noopener noreferrer" href="', $html);
    }

    /**
     * The start of the message as plain text, for lists and previews.
     */
    public function excerpt(int $length = 220): string
    {
        return Str::limit($this->plainText(), $length, preserveWords: true);
    }

    /**
     * Whether the preview leaves anything out (so "Read more" is worth it).
     */
    public function isLong(int $length = 220): bool
    {
        return mb_strlen($this->plainText()) > $length;
    }

    private function plainText(): string
    {
        $text = strip_tags(str_replace(['</p>', '</li>', '<br />', '</h1>', '</h2>', '</h3>'], ' ', $this->html()));

        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5)));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
