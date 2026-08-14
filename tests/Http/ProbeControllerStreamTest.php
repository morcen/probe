<?php

namespace Morcen\Probe\Http {
    function connection_aborted(): int
    {
        $state = \Morcen\Probe\Tests\Http\ProbeControllerStreamTestState::class;

        $state::$calls++;

        if ($state::$abortAfterCalls !== null && $state::$calls > $state::$abortAfterCalls) {
            return 1;
        }

        return $state::$aborted ? 1 : 0;
    }
}

namespace Morcen\Probe\Tests\Http {

    class ProbeControllerStreamTestState
    {
        public static bool $aborted = false;

        public static ?int $abortAfterCalls = null;

        public static int $calls = 0;
    }
}

namespace {

    use Illuminate\Support\Facades\DB;
    use Morcen\Probe\Tests\Http\ProbeControllerStreamTestState;

    beforeEach(function () {
        ProbeControllerStreamTestState::$aborted         = false;
        ProbeControllerStreamTestState::$abortAfterCalls = null;
        ProbeControllerStreamTestState::$calls           = 0;
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

    it('resumes from the Last-Event-ID header instead of replaying the entire history on reconnect', function () {
        app()->instance('probe.auth', fn ($request) => true);

        foreach (range(1, 3) as $i) {
            DB::table('probe_entries')->insert([
                'type'       => 'requests',
                'content'    => json_encode(['index' => $i]),
                'created_at' => now(),
            ]);
        }

        // Let the loop run one poll iteration (which should only emit the row
        // after the resume point), then abort before the next check so the
        // test doesn't block on the 60s deadline / sleep(3) keepalive cycle.
        ProbeControllerStreamTestState::$abortAfterCalls = 1;

        $response = $this->withHeader('Last-Event-ID', '2')->get('/probe/api/stream');
        $content  = $response->streamedContent();

        expect($content)
            ->toContain('id: 3')
            ->not->toContain('id: 1')
            ->not->toContain('id: 2');
    });
}
