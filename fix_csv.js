const fs = require('fs');
let code = fs.readFileSync('admin.html', 'utf8');

const replacementFunction = `function parseCSV(csvText) {
    // 1. Separamos por líneas limpiando retornos de carro y validando longitud
    const lines = csvText.split(/\\r?\\n/).filter(line => line.trim().length > 0);
    if (lines.length < 2) return [];

    // 2. Extraer cabeceras (la primera línea siempre las debe contener)
    // Usamos directamente un split dinámico considerando punto y coma O coma
    const delimiter = lines[0].includes(';') ? ';' : ',';

    // Función súper robusta para leer una línea y sus comillas dobles
    function parseLine(line) {
        const result = [];
        let current = '';
        let inQuotes = false;

        for (let i = 0; i < line.length; i++) {
            const char = line[i];

            if (char === '"') {
                if (inQuotes && line[i + 1] === '"') {
                    // Si encontramos "" dentro de comillas, lo guardamos como un solo "
                    current += '"';
                    i++; 
                } else {
                    // Abrimos o cerramos el estado de comillas
                    inQuotes = !inQuotes;
                }
            } else if (char === delimiter && !inQuotes) {
                // Si encontramos el delimitador y NO estamos dentro de comillas, cortamos campo
                result.push(current.trim());
                current = '';
            } else {
                current += char;
            }
        }
        // Pusheamos el último campo acumulado
        result.push(current.trim());
        return result;
    }

    // 3. Obtener el array de cabeceras mapeadas a las columnas que espera PHP
    const expectedHeaders = ['nombre', 'id_alumno', 'idioma', 'phone', 'intolerancias', 'linkedin', 'email'];
    
    // Si la cabecera real es algo como "Nombre,ID,Idioma...", pero tu sistema asume que la cabecera NO viene,
    // o viene mal escrita, obligaremos a usar la de los expectedHeaders directamente si detectamos fallo.
    
    // Leemos la primera línea
    let headersRaw = parseLine(lines[0]).map(h => {
        let key = h.toLowerCase().trim();
        if (key.charCodeAt(0) === 0xFEFF) key = key.substr(1); // BOM
        return key;
    });

    // Si la cabecera real del archivo CSV es muy distinta a la esperada, 
    // forzaremos el mapeo por POSICION de columna, en lugar de por NOMBRE de columna.
    // Esto previene que si subes un archivo con "Nombre Completo", el sistema lo ignore
    // porque busca "nombre".
    let usePositionalMapping = false;
    if (!headersRaw.includes('nombre') && !headersRaw.includes('id_alumno')) {
       usePositionalMapping = true;
    }

    const records = [];

    // 4. Iterar sobre las filas de datos
    for (let i = 1; i < lines.length; i++) {
        const values = parseLine(lines[i]);
        
        if (values.length === 0 || (values.length === 1 && values[0] === "")) continue;

        const record = {};
        
        if (usePositionalMapping) {
             // Si las cabeceras del CSV son raras, asignamos 
             // Columna 1 -> nombre
             // Columna 2 -> id_alumno
             // etc... basándonos en tu HTML.
             expectedHeaders.forEach((key, idx) => {
                 record[key] = values[idx] !== undefined ? values[idx].replace(/^"|"$/g, '').trim() : '';
             });
        } else {
             // Si el CSV trae buenas cabeceras ("nombre", "id_alumno"), mapeamos por el nombre
             headersRaw.forEach((key, idx) => {
                 // Si la cabecera coincide con alguna esperada (por ej. 'nombre'), la guardamos.
                 record[key] = values[idx] !== undefined ? values[idx].replace(/^"|"$/g, '').trim() : '';
             });
        }

        records.push(record);
    }

    return records;
}`;

// Reemplazar la función existente
const regex = /function parseCSV\(csvText\) \{[\s\S]*?return records;\n\}/;
if (regex.test(code)) {
    code = code.replace(regex, replacementFunction);
    fs.writeFileSync('admin.html', code);
    console.log("Archivo admin.html actualizado con el nuevo parser posicional/robusto.");
} else {
    console.log("No se pudo encontrar la función parseCSV en admin.html");
}

