<div>
</div>
<script>
    const url_params = new URLSearchParams(window.location.search);
    const player_id = url_params.get('player_id') ?? 8621659;
    // WebSconsole.log("🎮 Jugador:", player_id);

    // Función que crea un WebSocket y se reconecta si se cierra
    function create_socket(handler_name) {
        const socket_url = `wss://socket.aoe2companion.com/listen?handler=${handler_name}&profile_ids=${player_id}`;
        let socket = new WebSocket(socket_url);

        socket.onopen = () => {
            console.log(`✅ Conectado a ${handler_name}`);
        };

        socket.onmessage = (event) => {
            console.log(`📩 [${handler_name}] Mensaje recibido:`, event.data);

            // Aquí lanzas al backend lo recibido
            /*
            fetch('/api/analizar', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: event.data
            });
            */
        };

        socket.onclose = (event) => {
            console.warn(`❌ Conexión cerrada en ${handler_name}, reintentando en 3s...`, event.code, event.reason);
            setTimeout(() => {
                socket = create_socket(handler_name); // reconectar
            }, 3000);
        };

        socket.onerror = (error) => {
            console.error(`⚠️ Error en WebSocket ${handler_name}`, error);
            socket.close();
        };

        return socket;
    }
    const socket_match_started = create_socket("match-started");
    }
</script>