<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Aegis Mobile</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0f172a;
            color: #e2e8f0;
            height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .header {
            background: #1e293b;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid #334155;
            flex-shrink: 0;
        }
        .header h1 { font-size: 18px; font-weight: 600; }
        .header .badge {
            background: #3b82f6;
            color: white;
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 4px;
        }
        .messages {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .message {
            max-width: 85%;
            padding: 10px 14px;
            border-radius: 16px;
            font-size: 15px;
            line-height: 1.4;
        }
        .message.user {
            align-self: flex-end;
            background: #3b82f6;
            color: white;
            border-bottom-right-radius: 4px;
        }
        .message.assistant {
            align-self: flex-start;
            background: #1e293b;
            border: 1px solid #334155;
            border-bottom-left-radius: 4px;
        }
        .input-area {
            background: #1e293b;
            padding: 12px 16px;
            border-top: 1px solid #334155;
            display: flex;
            gap: 8px;
            flex-shrink: 0;
        }
        .input-area input {
            flex: 1;
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 20px;
            padding: 10px 16px;
            color: #e2e8f0;
            font-size: 15px;
            outline: none;
        }
        .input-area input:focus { border-color: #3b82f6; }
        .input-area button {
            background: #3b82f6;
            color: white;
            border: none;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            font-size: 18px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .empty-state {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #64748b;
            font-size: 16px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Aegis</h1>
        <span class="badge">Mobile</span>
    </div>
    <div class="messages" id="messages">
        <div class="empty-state">Send a message to start chatting</div>
    </div>
    <div class="input-area">
        <input type="text" id="messageInput" placeholder="Type a message..." autocomplete="off">
        <button onclick="sendMessage()" aria-label="Send">&#x2191;</button>
    </div>
    <script>
        const messagesEl = document.getElementById('messages');
        const inputEl = document.getElementById('messageInput');

        inputEl.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') sendMessage();
        });

        function sendMessage() {
            const text = inputEl.value.trim();
            if (!text) return;
            inputEl.value = '';

            const emptyState = messagesEl.querySelector('.empty-state');
            if (emptyState) emptyState.remove();

            appendMessage('user', text);
            appendMessage('assistant', 'Processing...');
        }

        function appendMessage(role, text) {
            const div = document.createElement('div');
            div.className = 'message ' + role;
            div.textContent = text;
            messagesEl.appendChild(div);
            messagesEl.scrollTop = messagesEl.scrollHeight;
        }
    </script>
</body>
</html>
