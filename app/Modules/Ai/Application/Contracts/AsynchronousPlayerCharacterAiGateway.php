<?php

namespace App\Modules\Ai\Application\Contracts;

/**
 * Marker for providers that accept a generation request and finish it later.
 */
interface AsynchronousPlayerCharacterAiGateway extends PlayerCharacterAiGateway {}
