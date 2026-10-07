// Generación del PDF de acreditaciones de presenciales (jsPDF + QR).
import QRCode from "qrcode";

const plantillaCache = {};

/** Carga una imagen de /public como dataURL (con caché). */
async function loadImage(url) {
  if (plantillaCache[url]) return plantillaCache[url];
  const blob = await (await fetch(url)).blob();
  const dataUrl = await new Promise((resolve) => {
    const fr = new FileReader();
    fr.onload = () => resolve(fr.result);
    fr.readAsDataURL(blob);
  });
  plantillaCache[url] = dataUrl;
  return dataUrl;
}

/**
 * Genera `alumnos-linkedin.pdf`: una página A4 por alumno sobre la plantilla
 * VIP o MAX. Con LinkedIn: nombre (20 pt) + QR; sin él: nombre grande (24 pt).
 * @param {Array<{nombre_diploma:string, vip:number|string, linkedin?:string}>} students
 */
export async function generateStudentsPdf(students) {
  // jsPDF se carga bajo demanda: pesa mucho y solo lo usa esta función
  const { jsPDF } = await import("jspdf");
  const pdf = new jsPDF({ orientation: "portrait", unit: "mm", format: "a4" });

  for (let i = 0; i < students.length; i++) {
    const s = students[i];
    if (i > 0) pdf.addPage();
    const isVip = Number(s.vip) === 1;
    pdf.addImage(await loadImage(isVip ? "/assets/plantilla_vip.jpg" : "/assets/plantilla_max.jpg"), "JPEG", 0, 0, 210, 297);
    pdf.setTextColor(0, 0, 0);

    if (s.linkedin) {
      pdf.setFont("helvetica", "bold");
      pdf.setFontSize(20);
      pdf.text(s.nombre_diploma, 105, 108, { align: "center", maxWidth: 70 });
      const qr = await QRCode.toDataURL(s.linkedin, { width: 300, margin: 1 });
      pdf.addImage(qr, "PNG", 70, 120, 70, 70);
    } else {
      pdf.setFont("helvetica", "bold");
      pdf.setFontSize(24);
      pdf.text(s.nombre_diploma, 105, 145, { align: "center", maxWidth: 80 });
    }
  }
  pdf.save("alumnos-linkedin.pdf");
}
