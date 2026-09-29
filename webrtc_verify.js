/**
 * webrtc_verify.js
 * ----------------------------------------------------
 * Kiểm tra "tính thật" của thiết bị/trình duyệt bằng WebRTC.
 * KHÔNG gọi camera/mic thật — chỉ:
 *   1. enumerateDevices() để đếm thiết bị
 *   2. RTCPeerConnection + STUN để kiểm tra có mạng thật
 *   3. Kiểm tra dấu hiệu headless / VM / automation
 *   4. Tạo fingerprint SHA-256 từ: UA, screen, TZ, WebGL, ICE, devices
 *
 * Trả về object:
 *   { ok: bool, reason: string, fingerprint: string, signals: {...} }
 */

async function verifyDevice() {
  const signals = {
    hasWebRTC: false,
    hasMediaDevices: false,
    deviceCount: 0,
    hasRealAudioInput: false,
    hasRealVideoInput: false,
    iceCandidates: 0,
    hasHostCandidate: false,
    hasSrflxCandidate: false,
    isHeadless: false,
    isVirtualUA: false,
    webglVendor: '',
    webglRenderer: '',
    screen: '',
    timezone: '',
    hardwareConcurrency: 0,
    deviceMemory: 0,
  };

  try {
    // ---------- 1. Kiểm tra WebRTC tồn tại ----------
    if (typeof RTCPeerConnection === 'undefined') {
      return fail('Trình duyệt không hỗ trợ WebRTC', signals);
    }
    signals.hasWebRTC = true;

    if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) {
      return fail('Không truy cập được mediaDevices', signals);
    }
    signals.hasMediaDevices = true;

    // ---------- 2. Liệt kê thiết bị ----------
    const devices = await navigator.mediaDevices.enumerateDevices();
    signals.deviceCount = devices.length;

    // Lưu ý: khi chưa cấp quyền, label rỗng nhưng kind vẫn có
    for (const d of devices) {
      if (d.kind === 'audioinput' && d.deviceId !== 'default') {
        signals.hasRealAudioInput = true;
      }
      if (d.kind === 'videoinput' && d.deviceId !== 'default') {
        signals.hasRealVideoInput = true;
      }
    }

    // ---------- 3. Phát hiện headless / automation ----------
    if (navigator.webdriver === true) {
      signals.isHeadless = true;
      return fail('Phát hiện automation (webdriver)', signals);
    }
    if (/HeadlessChrome|PhantomJS|SlimerJS|Electron\/.*Headless/i.test(navigator.userAgent)) {
      signals.isHeadless = true;
      return fail('Phát hiện trình duyệt headless', signals);
    }
    // Chrome ẩn danh nhưng vẫn có nhiều dấu hiệu khác — chỉ chặn khi kết hợp nhiều tín hiệu
    if (!window.chrome && /Chrome\//.test(navigator.userAgent) && !/Edg\//.test(navigator.userAgent)) {
      // Có thể là Chromium chưa đăng ký — không chặn ngay, đánh dấu
    }

    // ---------- 4. Phát hiện UA ảo / VM ----------
    const ua = navigator.userAgent.toLowerCase();
    const virtualSignals = [
      /virtualbox/, /vmware/, /qemu/, /xen/,
      /silk/, // Amazon Silk — thường trong VM
      /phantom/, /selenium/, /puppeteer/, /playwright/,
    ];
    if (virtualSignals.some(re => re.test(ua))) {
      signals.isVirtualUA = true;
      return fail('Phát hiện môi trường ảo trong User-Agent', signals);
    }

    // ---------- 5. WebGL fingerprint ----------
    try {
      const canvas = document.createElement('canvas');
      const gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
      if (gl) {
        const dbg = gl.getExtension('WEBGL_debug_renderer_info');
        if (dbg) {
          signals.webglVendor = gl.getParameter(dbg.UNMASKED_VENDOR_WEBGL) || '';
          signals.webglRenderer = gl.getParameter(dbg.UNMASKED_RENDERER_WEBGL) || '';
        }
      }
    } catch (e) { /* ignore */ }

    // Renderer đáng ngờ (SwiftShader, llvmpipe → thường là VM/headless)
    if (/swiftshader|llvmpipe|software|mesa/i.test(signals.webglRenderer)) {
      return fail('Phát hiện renderer phần mềm (VM/headless)', signals);
    }

    // ---------- 6. ICE Candidates ----------
    const ice = await collectIceCandidates(3000);
    signals.iceCandidates = ice.length;
    signals.hasHostCandidate  = ice.some(c => / typ host/.test(c));
    signals.hasSrflxCandidate = ice.some(c => / typ srflx/.test(c));

    // Không có candidate nào → WebRTC bị chặn hoặc mạng ảo
    if (ice.length === 0) {
      return fail('Không thu được ICE candidate — WebRTC bị chặn', signals);
    }
    // Chỉ có host (LAN) mà không có srflx → có thể không có mạng thật ra Internet
    if (!signals.hasSrflxCandidate) {
      return fail('Không có kết nối STUN ra Internet — mạng ảo hoặc bị chặn', signals);
    }

    // ---------- 7. Thông tin phần cứng ----------
    signals.screen = `${screen.width}x${screen.height}x${screen.colorDepth}`;
    signals.timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
    signals.hardwareConcurrency = navigator.hardwareConcurrency || 0;
    signals.deviceMemory = navigator.deviceMemory || 0;

    // ---------- 8. Fingerprint tổng hợp (SHA-256) ----------
    const raw = [
      navigator.userAgent,
      navigator.language,
      navigator.platform,
      signals.screen,
      signals.timezone,
      signals.webglVendor,
      signals.webglRenderer,
      signals.hardwareConcurrency,
      signals.deviceMemory,
      signals.deviceCount,
      ice.join('|'),
    ].join('||');

    const fingerprint = await sha256(raw);

    return { ok: true, reason: 'OK', fingerprint, signals };
  } catch (err) {
    return fail('Lỗi kiểm tra: ' + err.message, signals);
  }

  // ---------- helper ----------
  function fail(reason, sig) {
    return { ok: false, reason, fingerprint: '', signals: sig };
  }
}

/**
 * Thu thập ICE candidate qua STUN công khai (Google).
 * Trả về mảng string candidate.
 */
function collectIceCandidates(timeoutMs = 3000) {
  return new Promise(resolve => {
    const candidates = [];
    let finished = false;

    const finish = () => {
      if (finished) return;
      finished = true;
      try { pc.close(); } catch (e) {}
      resolve(candidates);
    };

    let pc;
    try {
      pc = new RTCPeerConnection({
        iceServers: [
          { urls: 'stun:stun.l.google.com:19302' },
          { urls: 'stun:stun1.l.google.com:19302' },
        ],
      });
    } catch (e) {
      return resolve([]);
    }

    pc.onicecandidate = (e) => {
      if (e.candidate && e.candidate.candidate) {
        candidates.push(e.candidate.candidate);
      } else {
        finish();
      }
    };
    pc.onicegatheringstatechange = () => {
      if (pc.iceGatheringState === 'complete') finish();
    };

    // Tạo DataChannel để buộc khởi động ICE gathering
    try { pc.createDataChannel('probe'); } catch (e) {}

    pc.createOffer()
      .then(offer => pc.setLocalDescription(offer))
      .catch(() => finish());

    setTimeout(finish, timeoutMs);
  });
}

/** SHA-256 hex */
async function sha256(str) {
  const buf = new TextEncoder().encode(str);
  const hash = await crypto.subtle.digest('SHA-256', buf);
  return [...new Uint8Array(hash)].map(b => b.toString(16).padStart(2, '0')).join('');
}
