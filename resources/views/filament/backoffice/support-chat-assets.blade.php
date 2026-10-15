{{-- The GitHub PHP test job runs before Vite produces a manifest. In tests the
     chat listener is exercised by feature tests rather than loading built JS. --}}
@unless(app()->runningUnitTests())
    @vite('resources/js/backoffice-support-chat.js')
@endunless
