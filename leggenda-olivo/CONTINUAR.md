# Para continuar en una sesión nueva (con Gemini)

Objetivo: rehacer el video "La leggenda dell'olivo" (9:16, italiano) con el estilo del video de muestra:
ilustraciones cinematográficas tipo anime, dioses majestuosos (no infantiles), primeros planos,
movimiento de cámara lento, frases grandes blancas al centro, SIN títulos de escena NI nombres de personajes.
Solo el texto del guion (ver tabla en `tomas_para_imagenes.md`, columna Diálogo).

Pasos previstos:
1. Leer la clave desde la variable de entorno `GEMINI_API_KEY`.
2. Generar las 30 imágenes de `tomas_para_imagenes.md` con un modelo de imagen de Gemini, manteniendo a los personajes iguales en todas las tomas.
3. Generar las voces en italiano con Gemini TTS (una voz distinta por personaje; Zeus y Poseidón graves y solemnes).
4. Montaje: reutilizar `sorgenti/` (timeline, subtítulos palabra por palabra, mezcla con música, audio estéreo) cambiando los dibujos por las imágenes.
