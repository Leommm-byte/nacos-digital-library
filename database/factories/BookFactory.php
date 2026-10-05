<?php

namespace Database\Factories;

use App\Enums\BookSource;
use App\Enums\BookStatus;
use App\Enums\Level;
use App\Models\Book;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\Support\DemoPdf;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;

/**
 * @extends Factory<Book>
 */
class BookFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => rtrim(fake()->sentence(fake()->numberBetween(2, 6)), '.'),
            'author' => fake()->name(),
            'description' => fake()->paragraph(),
            'department_id' => Department::factory(),
            'level' => fake()->randomElement(Level::cases()),
            'page_count' => fake()->numberBetween(20, 600),
        ];
    }

    public function uploadedBy(User $user): static
    {
        return $this->afterMaking(fn (Book $book) => $book->uploader_id = $user->id);
    }

    public function approved(): static
    {
        return $this->afterMaking(function (Book $book): void {
            $book->status = BookStatus::Approved;
            $book->approved_at = now();
        });
    }

    public function status(BookStatus $status): static
    {
        return $this->afterMaking(fn (Book $book) => $book->status = $status);
    }

    /**
     * Gives the book a real (generated) PDF on the private disk, with the
     * given number of pages or a random 6 to 14.
     */
    public function withPdf(?int $pages = null): static
    {
        return $this->afterCreating(function (Book $book) use ($pages): void {
            $pages ??= fake()->numberBetween(6, 14);
            $pdf = DemoPdf::make($book->title, $pages);
            $path = 'books/'.$book->public_id.'.pdf';

            Storage::disk('private')->put($path, $pdf);

            $book->files()->create([
                'disk' => 'private',
                'path' => $path,
                'original_name' => 'book.pdf',
                'mime' => 'application/pdf',
                'size_bytes' => strlen($pdf),
                'sha256' => hash('sha256', $pdf),
                'page_count' => $pages,
                'source' => BookSource::Pdf,
                'is_current' => true,
            ]);

            $book->forceFill(['page_count' => $pages])->save();
        });
    }
}
