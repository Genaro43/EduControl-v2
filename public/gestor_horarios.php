<?php
// gestor_horarios.php
require_once '../includes/config.php';

?>
<!doctype html>
<html lang="es">
<head>
    <link rel="icon" href="src/img/cecyteh.ico" type="image/x-icon">
    <meta charset="utf-8">
    <title>EduControl</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="../build/css/app.css?v=<?php echo CSS_VERSION; ?>">
<body>
    <div class="app">
        <header class="app__header">
            <div class="logo"></div>
        </header>

        <main class="app__main">
            <div class="card gestor-card">
                <h2 class="card__title">Asignar Horario</h2>
                
                <form id="formGestor" class="form-gestor">
                    <div id="mensajeGestor" class="form-gestor__mensaje"></div>

                    <div class="form-gestor__row">
                        <div class="campo">
                            <label for="id_dia" class="txBlack">Día</label>
                            <select id="id_dia" required>
                                <option value="">Selecciona...</option>
                                <option value="1">Lunes</option>
                                <option value="2">Martes</option>
                                <option value="3">Miércoles</option>
                                <option value="4">Jueves</option>
                                <option value="5">Viernes</option>
                            </select>
                        </div>
                        <div class="campo">
                            <label for="id_horario" class="txBlack">Hora</label>
                            <select id="id_horario" required>
                                <option value="">Cargando...</option>
                            </select>
                        </div>
                    </div>

                    <div class="campo">
                        <label for="id_aula" class="txBlack">Aula</label>
                        <select id="id_aula" required>
                            <option value="">Cargando...</option>
                        </select>
                    </div>

                    <div class="form-gestor__row">
                        <div class="campo">
                            <label for="grupo" class="txBlack">Grupo</label>
                            <select id="grupo" required>
                                <option value="">Cargando...</option>
                            </select>
                        </div>
                        <div class="campo">
                            <label for="maestro" class="txBlack">Maestro</label>
                            <select id="maestro" required>
                                <option value="">Cargando...</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-gestor__acciones">
                        <button type="submit" class="btn">Guardar Asignación</button>
                    </div>
                </form>
            </div>
        </main>
    </div>

<script>
const API_BASE = '../api';

// Función genérica para poblar selects
async function cargarSelect(url, selectId, valueKey, labelFn) {
    try {
        const res = await fetch(API_BASE + url);
        const data = await res.json();
        const select = document.getElementById(selectId);
        select.innerHTML = '<option value="">Selecciona...</option>';
        
        if (data.ok) {
            // Buscamos el array dentro del JSON (puede ser 'aulas', 'grupos', etc.)
            const items = data.aulas || data.grupos || data.maestros || data.horarios;
            items.forEach(item => {
                const opt = document.createElement('option');
                opt.value = item[valueKey];
                opt.textContent = labelFn(item);
                select.appendChild(opt);
            });
        }
    } catch (e) {
        console.error('Error cargando ' + selectId, e);
        document.getElementById(selectId).innerHTML = '<option value="">Error al cargar</option>';
    }
}

// Cargar todos los catálogos al iniciar
window.addEventListener('DOMContentLoaded', () => {
    cargarSelect('/get_horarios.php', 'id_horario', 'id', i => i.label);
    cargarSelect('/get_aulas.php', 'id_aula', 'id', i => i.nombre);
    cargarSelect('/get_grupos.php', 'grupo', 'id', i => `${i.semestre} ${i.grupo}`);
    cargarSelect('/get_maestros.php', 'maestro', 'id', i => i.nombre);
});

// Manejar el envío del formulario
document.getElementById('formGestor').addEventListener('submit', async (e) => {
    e.preventDefault();
    const msj = document.getElementById('mensajeGestor');
    msj.style.display = 'none';
    msj.className = 'form-gestor__mensaje';

    const payload = {
        id_dia: document.getElementById('id_dia').value,
        id_horario: document.getElementById('id_horario').value,
        id_aula: document.getElementById('id_aula').value,
        grupo: document.getElementById('grupo').value,
        maestro: document.getElementById('maestro').value
    };

    try {
        const res = await fetch(API_BASE + '/save_ocupacion.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();

        msj.style.display = 'block';
        if (data.ok) {
            msj.textContent = '¡Asignación guardada con éxito!';
            msj.classList.add('form-gestor__mensaje--exito');
            // Opcional: resetear campos si quieres que asignen rápido otro
            // document.getElementById('formGestor').reset();
        } else {
            msj.textContent = 'Error: ' + data.error;
            msj.classList.add('form-gestor__mensaje--error');
        }
    } catch (err) {
        msj.style.display = 'block';
        msj.textContent = 'Error de conexión con el servidor.';
        msj.classList.add('form-gestor__mensaje--error');
    }
});
</script>
</body>
</html>