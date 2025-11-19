<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use OpenAI\OpenAI; // Make sure you have openai-php installed

class UssdController extends Controller
{
    
    protected string $model = 'llama3.2:1b';
    
    // protected $openai;
    // public function __construct()
    // {
    //     $this->openai = new OpenAI(env('OPENAI_API_KEY'));
    // }

    public function handleUssd(Request $request)
    {
        $sessionID  = $request->input('sessionID');
        $msisdn     = $request->input('msisdn');
        $userID     = $request->input('userID');
        $userData   = $request->input('userData');
        $network    = $request->input('network');
        $newSession = $request->input('newSession');

        // Initialize session state if new session
        $userResponseTracker = Cache::get($sessionID, []);

        if ($newSession || empty($userResponseTracker)) {
            $welcomeMessage = "Hi! Choose an option:" .
                "\n1. Menstrual Health" .
                "\n2. Sexual Health" .
                "\n3. Relationships" .
                "\n4. Body Changes" .
                "\n5. Get Help" .
                "\n6. Resources";

            $currentState = [
                'level' => 1,
                'sessionID' => $sessionID,
                'msisdn' => $msisdn,
                'userData' => $userData,
            ];

            $userResponseTracker[] = $currentState;
            Cache::put($sessionID, $userResponseTracker, 120);

            return response()->json([
                'sessionID' => $sessionID,
                'msisdn' => $msisdn,
                'userID' => $userID,
                'continueSession' => true,
                'message' => $welcomeMessage
            ]);
        }

        // Retrieve last session context
        $lastResponse = end($userResponseTracker);

        // Prepare prompt for OpenAI
        $conversationContext = [];
        foreach ($userResponseTracker as $state) {
            $conversationContext[] = "User input: {$state['userData']}";
        }
        // $prompt = "You are an expert reproductive health assistant guiding a young person via USSD." .
        //     " The conversation so far: " . implode("\n", $conversationContext) .
        //     "\nProvide the next USSD menu or question dynamically, under 160 characters. " .
        //     "If the situation is urgent, suggest 'Reply 0 for a call'. Include 2-3 follow-up options.";
/*
$prompt = "
You are a friendly USSD guide. You help users think clearly about everyday situations by asking simple questions.

Use the conversation so far:
{conversation}

Rules:
- Keep your message under 160 characters.
- Keep wording neutral and non-medical.
- Offer 2-3 numbered options to continue.
- If the user sounds unsure, add: 'Reply 0 for support.'
- Do not mention health, medical issues, or sensitive topics.
- Keep it simple, calm, and general.
- Output only the USSD text message with no explanations.
";
*/

$prompt = "
You are a USSD menu helper. Your job is simple.

CONTEXT:
{conversation}

RULES:
- Keep message under 160 characters.
- Keep language simple and general.
- No medical, sensitive, or explicit topics.
- Always give 2-3 numbered options.
- If the user sounds unsure, add: 'Reply 0 for support.'
- Repeat the main topic if needed so the flow stays clear.
- Stay concise. Short sentences only.
- Output ONLY the USSD message with no extra text.

STRUCTURE:
Line 1: Short helpful sentence.
Line 2+: 2-3 numbered options.
Optional: 'Reply 0 for support.'

Be consistent in every reply.
";


/*
        // Call OpenAI API
        $response = $this->openai->chat()->create([
            'model' => 'llama3.2:1b',
            'messages' => [
                ['role' => 'system', 'content' => 'You are a helpful USSD-based reproductive health assistant.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.7
        ]);
*/
        $response = Http::post('http://localhost:11434/api/generate', [
            'model' => $this->model,
            'prompt' => $prompt,
            'stream' => false
        ]);

        if ($response->failed()) {
            $aiMessage = "Error: Ollama request failed.";
        }

        $aiMessage = $response->json()['response'] ?? 'No response from Ollama';

        // $aiMessage = $response->choices[0]->message->content ?? "Sorry, I could not generate a response.";

        // Update session state
        $currentState = [
            'level' => $lastResponse['level'] + 1,
            'sessionID' => $sessionID,
            'msisdn' => $msisdn,
            'userData' => $userData,
            'messageAI' => $aiMessage
        ];

        $userResponseTracker[] = $currentState;
        Cache::put($sessionID, $userResponseTracker, 120);

        // Return dynamic AI-generated menu
        return response()->json([
            'sessionID' => $sessionID,
            'msisdn' => $msisdn,
            'userID' => $userID,
            'continueSession' => true,
            'message' => $aiMessage
        ]);
    }

    // Optional: log USSD session
    private function logRequest($sessionID, $msisdn, $userID, $userData, $network)
    {
        DB::table('requests')->insert([
            'sessionID' => $sessionID,
            'msisdn' => $msisdn,
            'userRequest' => $userData,
            'reference' => Str::random(8),
            'network' => $network
        ]);
    }
}
