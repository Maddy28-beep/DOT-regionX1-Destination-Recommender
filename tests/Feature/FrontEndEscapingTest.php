<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Text that does not come from us (chat answers, OpenStreetMap road names) must never be parsed as HTML. */
class FrontEndEscapingTest extends TestCase
{
    public function test_the_chat_widget_escapes_quotes_before_it_makes_links(): void
    {
        $source = file_get_contents(resource_path('views/partials/chatbot-widget.blade.php'));

        $this->assertStringContainsString(".replace(/\"/g, '&quot;')", $source);
        $this->assertStringContainsString("(?:[^\s&]|&amp;)+", $source, 'a link address stops at the first escaped character');
    }

    public function test_route_steps_are_added_as_text_not_html(): void
    {
        $source = file_get_contents(resource_path('views/partials/route-map.blade.php'));

        $this->assertStringNotContainsString('li.innerHTML', $source);
        $this->assertStringContainsString('createTextNode(instruction', $source);
    }
}
