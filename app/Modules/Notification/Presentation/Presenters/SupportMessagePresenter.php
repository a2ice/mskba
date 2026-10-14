<?php

namespace App\Modules\Notification\Presentation\Presenters;

use App\Modules\Notification\Domain\Models\RequestThreadMessage;

/** Keep the historical FAQ message intact in storage; separate its page link only for display. */
final class SupportMessagePresenter
{
    /** @return array{kind: string, id: string, text: string, at: mixed, sender: mixed, source_path: ?string} */
    public function present(RequestThreadMessage $message): array
    {
        $parts = $this->parts($message->body);

        return [
            'kind' => 'message',
            'id' => 'message-'.$message->id,
            'text' => $parts['text'],
            'at' => $message->created_at,
            'sender' => $message->sender,
            'source_path' => $parts['source_path'],
        ];
    }

    public function preview(string $body): string
    {
        return $this->parts($body)['text'];
    }

    /** @return array{text: string, source_path: ?string} */
    private function parts(string $body): array
    {
        $prefix = 'Страница обращения: ';
        if (! str_starts_with($body, $prefix)) {
            return ['text' => $body, 'source_path' => null];
        }

        $pieces = explode("\n\n", $body, 2);
        if (count($pieces) !== 2) {
            return ['text' => $body, 'source_path' => null];
        }

        $path = substr($pieces[0], strlen($prefix));
        // A path must stay on this portal. Reject protocol-relative URLs,
        // backslashes, whitespace, query strings and fragments.
        if (! preg_match('~\A/(?!/)[^\s\\\\?#]+\z~u', $path)) {
            return ['text' => $body, 'source_path' => null];
        }

        return ['text' => $pieces[1], 'source_path' => $path];
    }
}
