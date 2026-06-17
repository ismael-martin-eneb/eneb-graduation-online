const fs = require('fs');
let code = fs.readFileSync('admin.html', 'utf8');

const newFunction = `function parseCSV(csvText) {
    const lines = csvText.split(/\\r?\\n/).filter(line => line.trim().length > 0);
    if (lines.length < 2) return [];

    // Detectar el delimitador basado en la primera línea (coma o punto y coma)
    const delimiter = lines[0].includes(';') ? ';' : (lines[0].includes('\\t') ? '\\t' : ',');

    // Función para parsear una línea respetando comillas dobles
    function parseLine(line) {
        const result = [];
        let current = '';
        let inQuotes = false;
        for (let i = 0; i < line.length; i++) {
            const char = line[i];
            if (char === '"') {
                if (inQuotes && line[i + 1] === '"') {
                    current += '"';
                    i++; // saltar la siguiente comilla escapada
                } else {
                    inQuotes = !inQuotes;
                }
            } else if (char === delimiter && !inQuotes) {
                result.push(current.trim());
                current = '';
            } else {
                current += char;
            }
        }
        result.push(current.trim());
        return result;
    }

    // 1. Extraemos y normalizamos cabeceras (minúsculas, espacios a guiones bajos)
    const headers = parseLine(lines[0]).map(h => h.toLowerCase().replace(/ /g, '_'));

    const records = [];

    // 2. Procesamos los datos
    for (let i = 1; i < lines.length; i++) {
        const values = parseLine(lines[i]);
        
        if (values.length === 0 || (values.length === 1 && values[0] === "")) continue;

        const record = {};
        headers.forEach((key, idx) => {
            record[key] = values[idx] !== undefined ? values[idx] : '';
        });

        records.push(record);
    }

    return records;
}`;

// Reemplaza la antigua función (desde "function parseCSV" hasta el "return records;\n}")
const regex = /function parseCSV\(csvText\) \{[\s\S]*?return records;\n\}/;
if (regex.test(code)) {
    code = code.replace(regex, newFunction);
    fs.writeFileSync('admin.html', code);
    console.log("Archivo admin.html actualizado con éxito.");
} else {
    console.log("No se encontró la función parseCSV original.");
}
