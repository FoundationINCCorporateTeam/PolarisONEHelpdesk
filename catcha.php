<?php
/**
 * CATCHa - Server-side CAPTCHA Validation
 * 
 * This file validates the CAPTCHA challenges submitted from challenge.html
 * It re-computes expected answers using the provided seed and verifies all responses.
 */

// Set JSON response header for API-like responses
header('Content-Type: application/json');

// Configuration
define('MAX_TIMESTAMP_AGE', 300); // 5 minutes max age for CAPTCHA
define('MIN_TIMESTAMP_AGE', 1);   // At least 1 second (prevent instant submissions)

// Funny messages
$SUCCESS_MESSAGES = [
    "🎉 You passed! Enjoy your 100% organic human certification.",
    "✅ Congratulations! You're officially more human than a toaster.",
    "🏆 Welcome to the Human Club! Your membership card is in the mail (it's invisible).",
    "🌟 Success! The robots are jealous of your humanity.",
    "🎊 You did it! Your carbon-based life form status has been verified.",
    "👏 Amazing! You've proven you're not made of ones and zeros.",
    "🥳 Certified Human! Frame this moment, you've earned it.",
    "✨ Wonderful! Your neural network is definitely biological.",
    "🎯 Bullseye! You hit all the human marks perfectly.",
    "🦸 Hero status achieved! Bots everywhere are crying binary tears."
];

$ERROR_MESSAGES = [
    "missing_data" => [
        "🤖 Nice try, robot! You forgot to send some data.",
        "📭 Something's missing... just like a robot's sense of humor.",
        "❌ Incomplete submission. Did your circuits malfunction?"
    ],
    "invalid_seed" => [
        "🔧 Invalid seed detected. Are you trying to hack us with a calculator?",
        "🎲 That seed doesn't look right. Did you roll the wrong dice?",
        "⚙️ Seed validation failed. Have you tried turning yourself off and on again?"
    ],
    "expired" => [
        "⏰ Time's up! This CAPTCHA has expired like milk left out too long.",
        "🕐 Too slow! Even a sloth would've finished faster.",
        "⌛ Expired! The CAPTCHA got bored waiting for you."
    ],
    "too_fast" => [
        "⚡ Whoa there, speedy! No human is THAT fast.",
        "🏎️ Slow down! Even The Flash takes a moment to think.",
        "🚀 Suspiciously quick... are you a robot with a time machine?"
    ],
    "challenge_failed" => [
        "❌ Bot detected. I'm telling your mother.",
        "🚫 Come back when you're less... silicon.",
        "🤖 BEEP BOOP detected! Access denied.",
        "💀 Challenge failed! The CAPTCHA has spoken.",
        "🔒 Verification failed. Try being more human next time.",
        "👀 We're watching you, robot. Try again.",
        "🎭 Nice disguise, but we see through your robotic facade."
    ],
    "replay_attack" => [
        "🔄 Replay attack detected! Did you think we wouldn't notice?",
        "♻️ Recycling is good for the planet, not for CAPTCHAs.",
        "🔁 We've seen this one before. Get creative!"
    ]
];

// Seeded random function (must match JavaScript implementation)
function seededRandom(&$seed) {
    $x = sin($seed++) * 10000;
    return $x - floor($x);
}

// Shuffle array with seed (must match JavaScript implementation)
function shuffleArraySeeded($array, $seed) {
    $arr = $array;
    for ($i = count($arr) - 1; $i > 0; $i--) {
        $localSeed = $seed + $i;
        $j = floor(seededRandom($localSeed) * ($i + 1));
        $temp = $arr[$i];
        $arr[$i] = $arr[$j];
        $arr[$j] = $temp;
    }
    return $arr;
}

function getRandomItem($array, $seed) {
    return $array[floor(seededRandom($seed) * count($array))];
}

function getRandomMessage($messages) {
    return $messages[array_rand($messages)];
}

// Challenge definitions (must match JavaScript)
$EMOJI_QUESTIONS = [
    ['target' => '🌮', 'decoys' => ['🦊', '👑', '🤖', '🌶️', '🎸', '🚀']],
    ['target' => '🦊', 'decoys' => ['🌮', '👑', '🤖', '🌶️', '🎸', '🚀']],
    ['target' => '🤖', 'decoys' => ['🦊', '👑', '🌮', '🌶️', '🎸', '🚀']],
    ['target' => '🚀', 'decoys' => ['🦊', '👑', '🤖', '🌶️', '🎸', '🌮']],
    ['target' => '🎸', 'decoys' => ['🦊', '👑', '🤖', '🌶️', '🌮', '🚀']]
];

$RIDDLE_QUESTIONS = [
    ['a' => '4'],
    ['a' => '4'],
    ['a' => '7'],
    ['a' => 'human'],
    ['a' => 'bot'],
    ['a' => 'c']
];

// Store used nonces (in production, use Redis or database)
$nonce_file = sys_get_temp_dir() . '/catcha_nonces.json';

function getNonces() {
    global $nonce_file;
    if (file_exists($nonce_file)) {
        $data = json_decode(file_get_contents($nonce_file), true);
        // Clean old nonces (older than 10 minutes)
        $cutoff = time() - 600;
        $data = array_filter($data, function($timestamp) use ($cutoff) {
            return $timestamp > $cutoff;
        });
        return $data;
    }
    return [];
}

function saveNonce($seed, $timestamp) {
    global $nonce_file;
    $nonces = getNonces();
    $key = $seed . '_' . $timestamp;
    $nonces[$key] = time();
    file_put_contents($nonce_file, json_encode($nonces));
}

function isNonceUsed($seed, $timestamp) {
    $nonces = getNonces();
    $key = $seed . '_' . $timestamp;
    return isset($nonces[$key]);
}

// Response helper
function respond($success, $message, $details = []) {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'details' => $details,
        'timestamp' => time()
    ]);
    exit;
}

function errorResponse($type, $details = []) {
    global $ERROR_MESSAGES;
    $messages = $ERROR_MESSAGES[$type] ?? $ERROR_MESSAGES['challenge_failed'];
    respond(false, getRandomMessage($messages), $details);
}

function successResponse() {
    global $SUCCESS_MESSAGES;
    respond(true, getRandomMessage($SUCCESS_MESSAGES));
}

// Main validation
try {
    // Check request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        // Show a simple HTML page for non-POST requests
        header('Content-Type: text/html');
        echo '<!DOCTYPE html>
<html>
<head>
    <title>CATCHa Validator</title>
    <style>
        body { font-family: sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; background: #1a1a2e; color: #edf2f7; }
        .card { background: #16213e; padding: 40px; border-radius: 20px; text-align: center; max-width: 400px; }
        h1 { color: #00d9ff; }
        a { color: #00d9ff; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="card">
        <h1>🐱 CATCHa Validator</h1>
        <p>This endpoint validates CAPTCHA submissions.</p>
        <p>Please complete the challenge first:</p>
        <p><a href="challenge.html">→ Go to CATCHa Challenge</a></p>
    </div>
</body>
</html>';
        exit;
    }

    // Validate required fields
    $required = ['seed', 'timestamp', 'challenges', 'answers'];
    foreach ($required as $field) {
        if (!isset($_POST[$field]) || empty($_POST[$field])) {
            errorResponse('missing_data', ['missing_field' => $field]);
        }
    }

    $seed = intval($_POST['seed']);
    $timestamp = intval($_POST['timestamp']);
    $challenges = explode(',', $_POST['challenges']);
    $answers = json_decode($_POST['answers'], true);

    // Validate seed
    if ($seed < 1 || $seed > 1000000) {
        errorResponse('invalid_seed');
    }

    // Validate timestamp (prevent replay and timing attacks)
    $currentTime = time() * 1000; // Convert to milliseconds
    $age = ($currentTime - $timestamp) / 1000; // Age in seconds

    if ($age > MAX_TIMESTAMP_AGE) {
        errorResponse('expired');
    }

    if ($age < MIN_TIMESTAMP_AGE) {
        errorResponse('too_fast');
    }

    // Check for replay attack
    if (isNonceUsed($seed, $timestamp)) {
        errorResponse('replay_attack');
    }

    // Validate each challenge
    $allPassed = true;
    $failedChallenges = [];

    foreach ($challenges as $index => $type) {
        $key = $type . '_' . $index;
        
        if (!isset($answers[$key])) {
            $failedChallenges[] = ['challenge' => $key, 'reason' => 'missing_answer'];
            $allPassed = false;
            continue;
        }

        $answer = $answers[$key];
        $passed = false;

        switch ($type) {
            case 'emoji':
                $passed = validateEmoji($seed, $index, $answer);
                break;
            case 'riddle':
                $passed = validateRiddle($seed, $index, $answer);
                break;
            case 'slider':
                $passed = validateSlider($answer);
                break;
            case 'numbers':
                $passed = validateNumbers($answer);
                break;
            case 'mouse':
                $passed = validateMouse($answer);
                break;
            case 'timing':
                $passed = validateTiming($answer);
                break;
            case 'word':
                $passed = validateWord($answer);
                break;
            default:
                $failedChallenges[] = ['challenge' => $key, 'reason' => 'unknown_type'];
                $allPassed = false;
                continue 2;
        }

        if (!$passed) {
            $failedChallenges[] = ['challenge' => $key, 'reason' => 'incorrect'];
            $allPassed = false;
        }
    }

    if ($allPassed) {
        // Save nonce to prevent replay
        saveNonce($seed, $timestamp);
        successResponse();
    } else {
        errorResponse('challenge_failed', ['failed' => $failedChallenges]);
    }

} catch (Exception $e) {
    errorResponse('challenge_failed', ['error' => 'Internal validation error']);
}

// Validation functions

function validateEmoji($seed, $index, $answer) {
    global $EMOJI_QUESTIONS;
    
    if (!isset($answer['selected']) || !is_array($answer['selected'])) {
        return false;
    }

    // Recreate the grid using the same seed
    $localSeed = $seed + $index;
    $questionIndex = floor(seededRandom($localSeed) * count($EMOJI_QUESTIONS));
    $question = $EMOJI_QUESTIONS[$questionIndex];
    
    // Determine target count
    $targetSeed = $seed + $index + 100;
    $targetCount = 2 + floor(seededRandom($targetSeed) * 3); // 2-4 targets
    $decoyCount = 8 - $targetCount;
    
    // Build emoji array
    $emojis = [];
    for ($i = 0; $i < $targetCount; $i++) {
        $emojis[] = ['isTarget' => true];
    }
    
    $shuffleSeed = $seed + $index;
    $shuffledDecoys = shuffleArraySeeded($question['decoys'], $shuffleSeed);
    for ($i = 0; $i < $decoyCount; $i++) {
        $emojis[] = ['isTarget' => false];
    }
    
    // Shuffle with seed
    $finalSeed = $seed + $index + 50;
    $emojis = shuffleArraySeeded($emojis, $finalSeed);
    
    // Get expected indices
    $expected = [];
    foreach ($emojis as $i => $emoji) {
        if ($emoji['isTarget']) {
            $expected[] = $i;
        }
    }
    
    // Sort both arrays for comparison
    sort($expected);
    $selected = $answer['selected'];
    sort($selected);
    
    return $expected === $selected;
}

function validateRiddle($seed, $index, $answer) {
    global $RIDDLE_QUESTIONS;
    
    if (!isset($answer['value'])) {
        return false;
    }

    $localSeed = $seed + $index + 200;
    $questionIndex = floor(seededRandom($localSeed) * count($RIDDLE_QUESTIONS));
    $question = $RIDDLE_QUESTIONS[$questionIndex];
    
    return strtolower(trim($answer['value'])) === strtolower($question['a']);
}

function validateSlider($answer) {
    return isset($answer['complete']) && $answer['complete'] === true;
}

function validateNumbers($answer) {
    if (!isset($answer['sequence']) || !is_array($answer['sequence'])) {
        return false;
    }
    
    return $answer['sequence'] === [1, 2, 3, 4, 5];
}

function validateMouse($answer) {
    // Check if marked complete and has enough points
    if (!isset($answer['complete']) || !$answer['complete']) {
        return false;
    }
    
    // Verify minimum point count (should have moved enough)
    if (!isset($answer['pointCount']) || $answer['pointCount'] < 10) {
        return false;
    }
    
    return true;
}

function validateTiming($answer) {
    // User should have clicked the second button (order = 2)
    return isset($answer['clicked']) && $answer['clicked'] === 2;
}

function validateWord($answer) {
    if (!isset($answer['value'])) {
        return false;
    }
    
    $value = trim($answer['value']);
    
    // Must be at least 3 characters, only letters
    if (strlen($value) < 3) {
        return false;
    }
    
    if (!preg_match('/^[a-zA-Z]+$/', $value)) {
        return false;
    }
    
    return true;
}
?>
