const fs = require('fs');

const newFunction = `
function parseCSV(csvText) {
    const lines = csvText.split(/\\r?\\n/).filter(line => line.trim().length > 0);
    if (lines.length < 2) return [];

    const delimiter = lines[0].includes(';') ? ';' : (lines[0].includes('\\t') ? '\\t' : ',');

    function parseLine(line) {
        const result = [];
        let current = '';
        let inQuotes = false;
        for (let i = 0; i < line.length; i++) {
            const char = line[i];
            if (char === '"') {
                if (inQuotes && line[i + 1] === '"') {
                    current += '"';
                    i++;
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

    const headers = parseLine(lines[0]).map(h => {
        let key = h.toLowerCase().trim();
        if (key.charCodeAt(0) === 0xFEFF) {
            key = key.substr(1);
        }
        return key;
    });

    const records = [];

    for (let i = 1; i < lines.length; i++) {
        const values = parseLine(lines[i]);
        
        if (values.length === 0 || (values.length === 1 && values[0] === "")) continue;

        const record = {};
        headers.forEach((key, idx) => {
            record[key] = values[idx] !== undefined ? values[idx].replace(/^"|"$/g, '').trim() : '';
        });

        records.push(record);
    }

    return records;
}
`;

let code = fs.readFileSync('admin.html', 'utf8');

// Buscamos dónde insertarla. Por ejemplo antes de "function showCsvStatus"
code = code.replace('function showCsvStatus', newFunction + '\nfunction showCsvStatus');

fs.writeFileSync('admin.html', code);
