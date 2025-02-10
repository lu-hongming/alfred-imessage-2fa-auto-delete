<?php

use Alfred\Workflows\Workflow;

require __DIR__ . '/vendor/autoload.php';

// Determine how many minutes to look back
$lookBackMinutes = max(0, (int) @$argv[1]) ?: 15;

$workflow = new Workflow;

$dbPath = $_SERVER['HOME'] . '/Library/Messages/chat.db';

if (! is_readable($dbPath)) {
    $workflow->result()
             ->title('ERROR: Unable to Access Your Messages')
             ->subtitle('We were unable to access the file that contains your text messages')
             ->arg('')
             ->valid(true);
    echo $workflow->output();
    exit;
}

try {
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $workflow->result()
             ->title('ERROR: Unable to Access Your Messages')
             ->subtitle('We were unable to access the file that contains your text messages')
             ->arg('')
             ->valid(true);
    $workflow->result()
             ->title('Error Message:')
             ->subtitle($e->getMessage())
             ->arg('')
             ->valid(true);
    echo $workflow->output();
    exit;
}

$maxAttempts = 60; // 1 minute of attempts (60 seconds)
$attempt = 0;

while ($attempt < $maxAttempts) {
    try {
        $query = $db->query("
            select
                message.rowid,
                ifnull(handle.uncanonicalized_id, chat.chat_identifier) AS sender,
                message.service,
                datetime(message.date / 1000000000 + 978307200, 'unixepoch', 'localtime') AS message_date,
                message.text
            from
                message
                    left join chat_message_join
                            on chat_message_join.message_id = message.ROWID
                    left join chat
                            on chat.ROWID = chat_message_join.chat_id
                    left join handle
                            on message.handle_id = handle.ROWID
            where
                message.is_from_me = 0
                and message.text is not null
                and length(message.text) > 0
                and (
                    message.text glob '*[0-9][0-9][0-9]*'
                    or message.text glob '*[0-9][0-9][0-9][0-9]*'
                    or message.text glob '*[0-9][0-9][0-9][0-9][0-9]*'
                    or message.text glob '*[0-9][0-9][0-9][0-9][0-9][0-9]*'
                    or message.text glob '*[0-9][0-9][0-9]-[0-9][0-9][0-9]*'
                    or message.text glob '*[0-9][0-9][0-9][0-9][0-9][0-9][0-9]*'
                    or message.text glob '*[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]*'
                )
                and datetime(message.date / 1000000000 + strftime('%s', '2001-01-01'), 'unixepoch', 'localtime')
                        >= datetime('now', '-$lookBackMinutes minutes', 'localtime')
            order by
                message.date desc
            limit 100
        ");
    } catch (PDOException $e) {
        $workflow->result()
                 ->title('ERROR: Unable to Query Your Messages')
                 ->subtitle('We were unable to run the query that reads your text messages')
                 ->arg('')
                 ->valid(true);
        $workflow->result()
                 ->title('Error Message:')
                 ->subtitle($e->getMessage())
                 ->arg('')
                 ->valid(true);
        echo $workflow->output();
        exit;
    }

    $found = false;

    while ($message = $query->fetch(PDO::FETCH_ASSOC)) {
        $code = null;
        $text = $message['text'];

        // Remove URLs
        $text = preg_replace('/\b((https?|ftp|file):\/\/|www\.)[-A-Z0-9+&@#\/%?=~_|$!:,.;]*[A-Z0-9+&@#\/%=~_|$]/i', '', $text);

        // Skip now-empty messages
        $text = trim($text);

        if (empty($text)) {
            continue;
        }

        if (preg_match('/(^|\s|\R|\t|\b|G-|:)(\d{4,8})($|\s|\R|\t|\b|\.|,)/', $text, $matches)) {
            $code = $matches[2];
        } elseif (preg_match('/^(\d{4,8})(\sis your.*code)/', $text, $matches)) {
            $code = $matches[1];
        } elseif (preg_match('/(code:|is:)\s*(\d{4,8})($|\s|\R|\t|\b|\.|,)/i', $text, $matches)) {
            $code = $matches[2];
        } elseif (preg_match('/(code|is):?\s*(\d{3,8})($|\s|\R|\t|\b|\.|,)/i', $text, $matches)) {
            $code = $matches[2];
        } elseif (preg_match('/(^|code:|is:|\b)\s*(\d{3})-(\d{3})($|\s|\R|\t|\b|\.|,)/', $text, $matches)) {
            $first = $matches[2];
            $second = $matches[3];
            if (! preg_match('/(^|code:|is:|\b)\s*' . $first . '-' . $second . '-(\d{4})($|\s|\R|\t|\b|\.|,)/', $text, $matches)) {
                $code = $first . $second;
            }
        }

        if ($code) {
            $found = true;
            // Copy the code to the clipboard
            exec("echo '$code' | pbcopy");
            // Simulate a keystroke to paste the code
            exec('osascript -e \'tell application "System Events" to keystroke "v" using {command down}\'');
            echo "Code $code pasted.";
            break 2; // Exit both loops
        }
    }

    if (!$found) {
        sleep(1); // Wait 1 second before trying again
        $attempt++;
    }
}

if (!$found) {
    echo "No recent code found.";
    exit; // Optional: Stop further execution if needed.
}


/**
 * Format the date of the message
 *
 * @param string $date
 *
 * @return string
 */
function formatDate($date)
{
    $time = strtotime($date);

    if (date('m/d/Y', $time) === date('m/d/Y')) {
        return 'Today @ ' . date('g:ia', $time);
    }

    return date('M j @ g:ia', $time);
}

/**
 * Format the text of the message
 *
 * @param string $text
 *
 * @return string
 */
function formatText($text)
{
    return str_replace(
        ["\n", ':;'],
        ['; ', ':'],
        trim($text)
    );
}

/**
 * Format a sender number
 *
 * @param string $sender
 *
 * @return string
 */
function formatSender($sender)
{
    $sender = trim($sender, '+');

    if (strlen($sender) === 11 && substr($sender, 0, 1) === '1') {
        $sender = substr($sender, 1);
    }

    if (strlen($sender) === 10) {
        return substr($sender, 0, 3) . '-' . substr($sender, 3, 3) . '-' . substr($sender, 6, 4);
    }

    return $sender;
}
