<?php

namespace Tests\Feature\Components;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FileInputTest extends TestCase
{
    #[Test]
    public function it_renders_a_labelled_input_with_a_remove_button(): void
    {
        $this->withViewErrors([])->blade('<x-file-input name="cover" label="New cover" accept="image/png" button="Choose a cover" data-upload-cover />')
            ->assertSee('<label for="cover" class="field-label">New cover</label>', false)
            ->assertSee('name="cover"', false)
            ->assertSee('accept="image/png"', false)
            ->assertSee('data-upload-cover', false)
            ->assertSee('Choose a cover')
            ->assertSee('data-file-input-clear', false)
            ->assertSee('aria-label="Remove the chosen file"', false)
            ->assertDontSee('aria-invalid', false);
    }

    #[Test]
    public function an_error_from_its_bag_is_linked_to_the_input(): void
    {
        $this->withViewErrors(['timetable' => 'Upload the Excel file.'], 'import')
            ->blade('<x-file-input name="timetable" id="timetable-file" label="Timetable file" bag="import" hint="Excel or CSV." />')
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('aria-describedby="timetable-file-hint timetable-file-error"', false)
            ->assertSee('<p id="timetable-file-error" class="field-error">Upload the Excel file.</p>', false);

        // Another form's error isn't shown here.
        $this->withViewErrors(['timetable' => 'Upload the Excel file.'])
            ->blade('<x-file-input name="timetable" label="Timetable file" bag="import" />')
            ->assertDontSee('Upload the Excel file.');
    }
}
