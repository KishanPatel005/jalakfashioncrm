<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>QR Test with Permission</title>
  <script src="https://unpkg.com/html5-qrcode/minified/html5-qrcode.min.js"></script>
  <style>
    #reader {
      width: 300px;
      height: 300px;
      margin-top: 10px;
      display: none;
      border: 2px solid #000;
    }
  </style>
</head>
<body>
  <h2>Camera Test</h2>
  <button onclick="initScanner()">Open Camera</button>
  <div id="reader"></div>
  <div id="result" style="margin-top:10px; font-weight:bold; color:green;"></div>

  <script>
    let qrCode; // keep reference for stop/start

    async function initScanner() {
      try {
        // Request permission
        const stream = await navigator.mediaDevices.getUserMedia({ video: true });
        stream.getTracks().forEach(track => track.stop()); // stop temp stream

        // Show scanner div
        document.getElementById("reader").style.display = "block";

        startScanner();
      } catch (err) {
        alert("Camera permission denied or not available. Please allow camera access.");
        console.error("Permission error: ", err);
      }
    }

    function startScanner() {
      qrCode = new Html5Qrcode("reader");

      qrCode.start(
        { facingMode: "environment" }, // back camera on mobile
        { fps: 10, qrbox: 250 },
        qrCodeMessage => {
          document.getElementById("result").innerText = "Scanned: " + qrCodeMessage;
          qrCode.stop(); // stop after first scan
        },
        errorMessage => {
          // ignore scanning errors
        }
      ).catch(err => {
        console.error("Camera start error: ", err);
      });
    }
    async function initScanner() {
  try {
    const devices = await navigator.mediaDevices.enumerateDevices();
    const videoDevices = devices.filter(d => d.kind === "videoinput");
    if (videoDevices.length === 0) {
      alert("No camera found on this device.");
      return;
    }

    const stream = await navigator.mediaDevices.getUserMedia({ video: true });
    stream.getTracks().forEach(track => track.stop());

    document.getElementById("reader").style.display = "block";
    startScanner();
  } catch (err) {
    alert("Camera permission denied or not available.");
    console.error("Permission error: ", err);
  }
}

  </script>
</body>
</html>
