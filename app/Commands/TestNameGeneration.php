<?php

namespace App\Commands;

use App\Classes\RequestNameGenerator;
use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;
use function dump;
use function Laravel\Prompts\{outro, table, intro, warning, info, error};

class TestNameGeneration extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'name:generate';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle(): void
    {
        $endpointData = $this->testData();

        // Slice the array to only include the first 2 items
        $endpointData = array_slice($endpointData, 0, 15);

        intro('Generating Request Names...');
        $response = RequestNameGenerator::collection($endpointData);
        // dump($response);
        outro('Generated Request Names');
        table(['Method', 'Path', 'Summary', 'Request Class Name'], $response);
    }

    protected function testData(): array
    {
        $testData = <<<JSON
[
    {
        "method": "get",
        "path": "\/appointments\/{appointmentid}",
        "summary": "Get appointment details"
    },
    {
        "method": "get",
        "path": "\/appointments\/booked",
        "summary": "Get list of booked appointments"
    },
    {
        "method": "get",
        "path": "\/appointments\/booked\/multipledepartment",
        "summary": "Get list of booked appointments for multiple departments and providers"
    },
    {
        "method": "put",
        "path": "\/appointments\/{appointmentid}\/cancel",
        "summary": "Cancel appointment"
    },
    {
        "method": "put",
        "path": "\/appointments\/{appointmentid}\/reschedule",
        "summary": "Reschedule appointment"
    },
    {
        "method": "get",
        "path": "\/appointments\/getappointmentidbyhash\/{messagehash}",
        "summary": "Gets the appointment id tied to the confirmation hash in the appointment confirmation email"
    },
    {
        "method": "get",
        "path": "\/appointments\/{appointmentid}\/nativeathenatelehealthroom",
        "summary": "Retrieve athenaone telehealth invite url."
    },
    {
        "method": "post",
        "path": "\/appointments\/telehealth\/deeplink",
        "summary": "Retrieve athenaone telehealth deep link join url."
    },
    {
        "method": "get",
        "path": "\/appointments\/changed",
        "summary": "Get list of changes in appointment slots based on subscribed events"
    },
    {
        "method": "get",
        "path": "\/appointments\/changed\/subscription",
        "summary": "Get list of appointment slot change subscription(s)"
    },
    {
        "method": "get",
        "path": "\/appointments\/changed\/subscription\/events",
        "summary": "Get list of appointment slot change events to which you can subscribe"
    }
]
JSON;
        return json_decode($testData, true);
    }

}
