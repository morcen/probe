<?php

namespace Morcen\Probe\Http {
    function connection_aborted(): int
    {
        return \Morcen\Probe\Tests\Http\ProbeControllerStreamTestState::$aborted ? 1 : 0;
    }
}

namespace Morcen\Probe\Tests\Http {

    class ProbeControllerStreamTestState
    {
        public static bool $aborted = false;
    }
}

namespace {

    use Morcen\Probe\Tests\Http\ProbeControllerStreamTestState;

    beforeEach(function () {
        ProbeControllerStreamTestState::$aborted = false;
    });

    it('stops polling and exits the SSE loop as soon as the client disconnects', function () {
        app()->instance('probe.auth', fn ($request) => true);
        ProbeControllerStreamTestState::$aborted = true;

        $response = $this->get('/probe/api/stream');

        // Reading the streamed content executes the response callback; if the
        // connection_aborted() check didn't break the loop immediately, this
        // call would block for up to 60s of DB polling and sleep(3) cycles.
        $content = $response->streamedContent();

        expect($content)->toBe('');
    });
}
