# Método ALTA · Paylab — Kit de arranque

Uso interno. Todo lo necesario para arrancar el piloto con Clover, Getnet, Ossacra y GoCelular.

| Archivo | Para qué sirve | Quién lo usa |
|---|---|---|
| `Tablero_ALTA.xlsx` | Carga de conversaciones, Mapa de Ángulos (ya cargado con los anuncios de 90 días), Banco de Objeciones, Índice ALTA con fórmulas y checklist semanal | Asesores (todos los días) y responsable de pauta (lunes) |
| `asesor-ia/prompt_asesor_ia.md` | Instrucciones del Asesor IA: parte común + un bloque por cliente, registro para el sistema y plan de implementación en 3 pasos | Quien configure la IA en WhatsApp |
| `briefs/briefs_semana_1.md` | 9 anuncios nuevos (3 por cliente), cada uno ubicado en un hueco del Mapa de Ángulos, y la estructura de campaña propuesta | Equipo creativo |

El método completo está en el documento **Método ALTA · Paylab**.

---

## Plan de los primeros 30 días

### Semana 0 · Esta semana: decisiones
- [ ] Definir con cada cliente qué cuenta como **Alta Activa** y cómo nos van a pasar ese dato (primera venta del POS, primer aporte, pago acreditado y entrega).
- [ ] Confirmar qué herramienta de WhatsApp usan los asesores y si permite etiquetas, conectar IA y enviar eventos a Meta.
- [ ] Getnet: exportar del Administrador de Anuncios los últimos 90 días a nivel anuncio.
- [ ] Aprobar las consolidaciones propuestas (Clover y GoCelular en una campaña principal cada uno; Ossacra en una sola cuenta).
- [ ] Pedir a cada cliente lo que falta para los briefs (ver el final de `briefs_semana_1.md`).

### Semana 1 · Línea de base
- [ ] Los asesores empiezan a cargar cada conversación en la pestaña *Conversaciones*, con etapa y objeción.
- [ ] Contar, de los últimos 30 días, cuántas conversaciones terminaron en Alta Activa: esa es la **línea de base** de cada cliente.
- [ ] Producir los 9 anuncios de los briefs.
- [ ] Completar en *Banco de Objeciones* la respuesta que mejor funciona para cada objeción.

### Semana 2 · Primeros cambios (de a uno por cuenta)
- [ ] Subir los anuncios nuevos a la campaña principal de cada cliente.
- [ ] Aplicar la consolidación aprobada. Un cambio de estructura por cuenta y por semana, para poder leer el efecto.
- [ ] Asesor IA en modo **copiloto** en GoCelular (el de más volumen): sugiere respuestas y completa el registro; el asesor revisa y envía.

### Semana 3 · Señal conectada
- [ ] Empezar a enviar a Meta los eventos de Alta y Alta Activa desde la herramienta de WhatsApp o el CRM.
- [ ] Primer **Índice ALTA** de cada cuenta.
- [ ] Primera ronda del ciclo objeciones → anuncios: las 3 objeciones más repetidas se convierten en briefs.

### Semana 4 · Primera lectura
- [ ] Comparar CAA y tasa de activación contra la línea de base.
- [ ] Decidir si el Asesor IA pasa al paso 2 (responde primero) en GoCelular y si entra en copiloto en Ossacra y Clover.
- [ ] Escribir el primer mini caso con números de altas, no de mensajes.

Las semanas 5 y 6 completan el piloto: se repite el ritmo semanal y al final se escribe el caso de cada cliente.

---

## Reglas que no se negocian

1. **Ningún dato de un cliente se usa para otro.** Se comparten aprendizajes, no información. Clover y Getnet tienen equipos, asistentes y anuncios separados.
2. **El Asesor IA no inventa.** Solo responde con información confirmada por el cliente; lo demás lo deriva.
3. **Se mide en Altas Activas.** Costo por mensaje y CTR son datos de control, no el resultado.
4. **Un cambio de estructura por vez.** Andromeda en campañas de mensajes es terreno nuevo: validamos de a uno.
