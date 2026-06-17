const fs = require('fs');
let code = fs.readFileSync('admin.html', 'utf8');

// Modificamos el evento submit para hacer un console.log de los estudiantes antes de enviarlos a PHP.
// Esto nos ayudará a diagnosticar si el problema está en la fase JS o en la fase PHP.

code = code.replace(
    /const result = await response.json\(\);/,
    `const result = await response.json();`
).replace(
    /body: JSON\.stringify\(\{ students: students \}\)/,
    `body: (function(){ console.log("ESTUDIANTES ENVIADOS AL API:", students); return JSON.stringify({ students: students }); })()`
);

fs.writeFileSync('admin.html', code);
