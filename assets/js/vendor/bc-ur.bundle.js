/* @gandlaf21/bc-ur 1.1.12 (MIT) + buffer 6.0.3 (MIT), bundled by scripts/build-vendor-js.sh */
(() => {
  var __create = Object.create;
  var __defProp = Object.defineProperty;
  var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
  var __getOwnPropNames = Object.getOwnPropertyNames;
  var __getProtoOf = Object.getPrototypeOf;
  var __hasOwnProp = Object.prototype.hasOwnProperty;
  var __esm = (fn, res) => function __init() {
    return fn && (res = (0, fn[__getOwnPropNames(fn)[0]])(fn = 0)), res;
  };
  var __commonJS = (cb, mod) => function __require() {
    return mod || (0, cb[__getOwnPropNames(cb)[0]])((mod = { exports: {} }).exports, mod), mod.exports;
  };
  var __copyProps = (to, from, except, desc) => {
    if (from && typeof from === "object" || typeof from === "function") {
      for (let key of __getOwnPropNames(from))
        if (!__hasOwnProp.call(to, key) && key !== except)
          __defProp(to, key, { get: () => from[key], enumerable: !(desc = __getOwnPropDesc(from, key)) || desc.enumerable });
    }
    return to;
  };
  var __toESM = (mod, isNodeMode, target) => (target = mod != null ? __create(__getProtoOf(mod)) : {}, __copyProps(
    // If the importer is in node compatibility mode or this is not an ESM
    // file that has been converted to a CommonJS file using a Babel-
    // compatible transform (i.e. "__esModule" has not been set), then set
    // "default" to the CommonJS "module.exports" for node compatibility.
    isNodeMode || !mod || !mod.__esModule ? __defProp(target, "default", { value: mod, enumerable: true }) : target,
    mod
  ));

  // node_modules/base64-js/index.js
  var require_base64_js = __commonJS({
    "node_modules/base64-js/index.js"(exports) {
      "use strict";
      init_buffer_shim();
      exports.byteLength = byteLength;
      exports.toByteArray = toByteArray;
      exports.fromByteArray = fromByteArray;
      var lookup = [];
      var revLookup = [];
      var Arr = typeof Uint8Array !== "undefined" ? Uint8Array : Array;
      var code = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
      for (i = 0, len = code.length; i < len; ++i) {
        lookup[i] = code[i];
        revLookup[code.charCodeAt(i)] = i;
      }
      var i;
      var len;
      revLookup["-".charCodeAt(0)] = 62;
      revLookup["_".charCodeAt(0)] = 63;
      function getLens(b64) {
        var len2 = b64.length;
        if (len2 % 4 > 0) {
          throw new Error("Invalid string. Length must be a multiple of 4");
        }
        var validLen = b64.indexOf("=");
        if (validLen === -1) validLen = len2;
        var placeHoldersLen = validLen === len2 ? 0 : 4 - validLen % 4;
        return [validLen, placeHoldersLen];
      }
      function byteLength(b64) {
        var lens = getLens(b64);
        var validLen = lens[0];
        var placeHoldersLen = lens[1];
        return (validLen + placeHoldersLen) * 3 / 4 - placeHoldersLen;
      }
      function _byteLength(b64, validLen, placeHoldersLen) {
        return (validLen + placeHoldersLen) * 3 / 4 - placeHoldersLen;
      }
      function toByteArray(b64) {
        var tmp;
        var lens = getLens(b64);
        var validLen = lens[0];
        var placeHoldersLen = lens[1];
        var arr = new Arr(_byteLength(b64, validLen, placeHoldersLen));
        var curByte = 0;
        var len2 = placeHoldersLen > 0 ? validLen - 4 : validLen;
        var i2;
        for (i2 = 0; i2 < len2; i2 += 4) {
          tmp = revLookup[b64.charCodeAt(i2)] << 18 | revLookup[b64.charCodeAt(i2 + 1)] << 12 | revLookup[b64.charCodeAt(i2 + 2)] << 6 | revLookup[b64.charCodeAt(i2 + 3)];
          arr[curByte++] = tmp >> 16 & 255;
          arr[curByte++] = tmp >> 8 & 255;
          arr[curByte++] = tmp & 255;
        }
        if (placeHoldersLen === 2) {
          tmp = revLookup[b64.charCodeAt(i2)] << 2 | revLookup[b64.charCodeAt(i2 + 1)] >> 4;
          arr[curByte++] = tmp & 255;
        }
        if (placeHoldersLen === 1) {
          tmp = revLookup[b64.charCodeAt(i2)] << 10 | revLookup[b64.charCodeAt(i2 + 1)] << 4 | revLookup[b64.charCodeAt(i2 + 2)] >> 2;
          arr[curByte++] = tmp >> 8 & 255;
          arr[curByte++] = tmp & 255;
        }
        return arr;
      }
      function tripletToBase64(num) {
        return lookup[num >> 18 & 63] + lookup[num >> 12 & 63] + lookup[num >> 6 & 63] + lookup[num & 63];
      }
      function encodeChunk(uint8, start, end) {
        var tmp;
        var output = [];
        for (var i2 = start; i2 < end; i2 += 3) {
          tmp = (uint8[i2] << 16 & 16711680) + (uint8[i2 + 1] << 8 & 65280) + (uint8[i2 + 2] & 255);
          output.push(tripletToBase64(tmp));
        }
        return output.join("");
      }
      function fromByteArray(uint8) {
        var tmp;
        var len2 = uint8.length;
        var extraBytes = len2 % 3;
        var parts = [];
        var maxChunkLength = 16383;
        for (var i2 = 0, len22 = len2 - extraBytes; i2 < len22; i2 += maxChunkLength) {
          parts.push(encodeChunk(uint8, i2, i2 + maxChunkLength > len22 ? len22 : i2 + maxChunkLength));
        }
        if (extraBytes === 1) {
          tmp = uint8[len2 - 1];
          parts.push(
            lookup[tmp >> 2] + lookup[tmp << 4 & 63] + "=="
          );
        } else if (extraBytes === 2) {
          tmp = (uint8[len2 - 2] << 8) + uint8[len2 - 1];
          parts.push(
            lookup[tmp >> 10] + lookup[tmp >> 4 & 63] + lookup[tmp << 2 & 63] + "="
          );
        }
        return parts.join("");
      }
    }
  });

  // node_modules/ieee754/index.js
  var require_ieee754 = __commonJS({
    "node_modules/ieee754/index.js"(exports) {
      init_buffer_shim();
      /*! ieee754. BSD-3-Clause License. Feross Aboukhadijeh <https://feross.org/opensource> */
      exports.read = function(buffer2, offset, isLE, mLen, nBytes) {
        var e, m;
        var eLen = nBytes * 8 - mLen - 1;
        var eMax = (1 << eLen) - 1;
        var eBias = eMax >> 1;
        var nBits = -7;
        var i = isLE ? nBytes - 1 : 0;
        var d = isLE ? -1 : 1;
        var s = buffer2[offset + i];
        i += d;
        e = s & (1 << -nBits) - 1;
        s >>= -nBits;
        nBits += eLen;
        for (; nBits > 0; e = e * 256 + buffer2[offset + i], i += d, nBits -= 8) {
        }
        m = e & (1 << -nBits) - 1;
        e >>= -nBits;
        nBits += mLen;
        for (; nBits > 0; m = m * 256 + buffer2[offset + i], i += d, nBits -= 8) {
        }
        if (e === 0) {
          e = 1 - eBias;
        } else if (e === eMax) {
          return m ? NaN : (s ? -1 : 1) * Infinity;
        } else {
          m = m + Math.pow(2, mLen);
          e = e - eBias;
        }
        return (s ? -1 : 1) * m * Math.pow(2, e - mLen);
      };
      exports.write = function(buffer2, value, offset, isLE, mLen, nBytes) {
        var e, m, c;
        var eLen = nBytes * 8 - mLen - 1;
        var eMax = (1 << eLen) - 1;
        var eBias = eMax >> 1;
        var rt = mLen === 23 ? Math.pow(2, -24) - Math.pow(2, -77) : 0;
        var i = isLE ? 0 : nBytes - 1;
        var d = isLE ? 1 : -1;
        var s = value < 0 || value === 0 && 1 / value < 0 ? 1 : 0;
        value = Math.abs(value);
        if (isNaN(value) || value === Infinity) {
          m = isNaN(value) ? 1 : 0;
          e = eMax;
        } else {
          e = Math.floor(Math.log(value) / Math.LN2);
          if (value * (c = Math.pow(2, -e)) < 1) {
            e--;
            c *= 2;
          }
          if (e + eBias >= 1) {
            value += rt / c;
          } else {
            value += rt * Math.pow(2, 1 - eBias);
          }
          if (value * c >= 2) {
            e++;
            c /= 2;
          }
          if (e + eBias >= eMax) {
            m = 0;
            e = eMax;
          } else if (e + eBias >= 1) {
            m = (value * c - 1) * Math.pow(2, mLen);
            e = e + eBias;
          } else {
            m = value * Math.pow(2, eBias - 1) * Math.pow(2, mLen);
            e = 0;
          }
        }
        for (; mLen >= 8; buffer2[offset + i] = m & 255, i += d, m /= 256, mLen -= 8) {
        }
        e = e << mLen | m;
        eLen += mLen;
        for (; eLen > 0; buffer2[offset + i] = e & 255, i += d, e /= 256, eLen -= 8) {
        }
        buffer2[offset + i - d] |= s * 128;
      };
    }
  });

  // node_modules/buffer/index.js
  var require_buffer = __commonJS({
    "node_modules/buffer/index.js"(exports) {
      "use strict";
      init_buffer_shim();
      /*!
       * The buffer module from node.js, for the browser.
       *
       * @author   Feross Aboukhadijeh <https://feross.org>
       * @license  MIT
       */
      var base64 = require_base64_js();
      var ieee754 = require_ieee754();
      var customInspectSymbol = typeof Symbol === "function" && typeof Symbol["for"] === "function" ? Symbol["for"]("nodejs.util.inspect.custom") : null;
      exports.Buffer = Buffer11;
      exports.SlowBuffer = SlowBuffer;
      exports.INSPECT_MAX_BYTES = 50;
      var K_MAX_LENGTH = 2147483647;
      exports.kMaxLength = K_MAX_LENGTH;
      Buffer11.TYPED_ARRAY_SUPPORT = typedArraySupport();
      if (!Buffer11.TYPED_ARRAY_SUPPORT && typeof console !== "undefined" && typeof console.error === "function") {
        console.error(
          "This browser lacks typed array (Uint8Array) support which is required by `buffer` v5.x. Use `buffer` v4.x if you require old browser support."
        );
      }
      function typedArraySupport() {
        try {
          const arr = new Uint8Array(1);
          const proto = { foo: function() {
            return 42;
          } };
          Object.setPrototypeOf(proto, Uint8Array.prototype);
          Object.setPrototypeOf(arr, proto);
          return arr.foo() === 42;
        } catch (e) {
          return false;
        }
      }
      Object.defineProperty(Buffer11.prototype, "parent", {
        enumerable: true,
        get: function() {
          if (!Buffer11.isBuffer(this)) return void 0;
          return this.buffer;
        }
      });
      Object.defineProperty(Buffer11.prototype, "offset", {
        enumerable: true,
        get: function() {
          if (!Buffer11.isBuffer(this)) return void 0;
          return this.byteOffset;
        }
      });
      function createBuffer(length) {
        if (length > K_MAX_LENGTH) {
          throw new RangeError('The value "' + length + '" is invalid for option "size"');
        }
        const buf = new Uint8Array(length);
        Object.setPrototypeOf(buf, Buffer11.prototype);
        return buf;
      }
      function Buffer11(arg, encodingOrOffset, length) {
        if (typeof arg === "number") {
          if (typeof encodingOrOffset === "string") {
            throw new TypeError(
              'The "string" argument must be of type string. Received type number'
            );
          }
          return allocUnsafe(arg);
        }
        return from(arg, encodingOrOffset, length);
      }
      Buffer11.poolSize = 8192;
      function from(value, encodingOrOffset, length) {
        if (typeof value === "string") {
          return fromString2(value, encodingOrOffset);
        }
        if (ArrayBuffer.isView(value)) {
          return fromArrayView(value);
        }
        if (value == null) {
          throw new TypeError(
            "The first argument must be one of type string, Buffer, ArrayBuffer, Array, or Array-like Object. Received type " + typeof value
          );
        }
        if (isInstance(value, ArrayBuffer) || value && isInstance(value.buffer, ArrayBuffer)) {
          return fromArrayBuffer(value, encodingOrOffset, length);
        }
        if (typeof SharedArrayBuffer !== "undefined" && (isInstance(value, SharedArrayBuffer) || value && isInstance(value.buffer, SharedArrayBuffer))) {
          return fromArrayBuffer(value, encodingOrOffset, length);
        }
        if (typeof value === "number") {
          throw new TypeError(
            'The "value" argument must not be of type number. Received type number'
          );
        }
        const valueOf = value.valueOf && value.valueOf();
        if (valueOf != null && valueOf !== value) {
          return Buffer11.from(valueOf, encodingOrOffset, length);
        }
        const b = fromObject(value);
        if (b) return b;
        if (typeof Symbol !== "undefined" && Symbol.toPrimitive != null && typeof value[Symbol.toPrimitive] === "function") {
          return Buffer11.from(value[Symbol.toPrimitive]("string"), encodingOrOffset, length);
        }
        throw new TypeError(
          "The first argument must be one of type string, Buffer, ArrayBuffer, Array, or Array-like Object. Received type " + typeof value
        );
      }
      Buffer11.from = function(value, encodingOrOffset, length) {
        return from(value, encodingOrOffset, length);
      };
      Object.setPrototypeOf(Buffer11.prototype, Uint8Array.prototype);
      Object.setPrototypeOf(Buffer11, Uint8Array);
      function assertSize(size) {
        if (typeof size !== "number") {
          throw new TypeError('"size" argument must be of type number');
        } else if (size < 0) {
          throw new RangeError('The value "' + size + '" is invalid for option "size"');
        }
      }
      function alloc2(size, fill, encoding) {
        assertSize(size);
        if (size <= 0) {
          return createBuffer(size);
        }
        if (fill !== void 0) {
          return typeof encoding === "string" ? createBuffer(size).fill(fill, encoding) : createBuffer(size).fill(fill);
        }
        return createBuffer(size);
      }
      Buffer11.alloc = function(size, fill, encoding) {
        return alloc2(size, fill, encoding);
      };
      function allocUnsafe(size) {
        assertSize(size);
        return createBuffer(size < 0 ? 0 : checked(size) | 0);
      }
      Buffer11.allocUnsafe = function(size) {
        return allocUnsafe(size);
      };
      Buffer11.allocUnsafeSlow = function(size) {
        return allocUnsafe(size);
      };
      function fromString2(string, encoding) {
        if (typeof encoding !== "string" || encoding === "") {
          encoding = "utf8";
        }
        if (!Buffer11.isEncoding(encoding)) {
          throw new TypeError("Unknown encoding: " + encoding);
        }
        const length = byteLength(string, encoding) | 0;
        let buf = createBuffer(length);
        const actual = buf.write(string, encoding);
        if (actual !== length) {
          buf = buf.slice(0, actual);
        }
        return buf;
      }
      function fromArrayLike(array) {
        const length = array.length < 0 ? 0 : checked(array.length) | 0;
        const buf = createBuffer(length);
        for (let i = 0; i < length; i += 1) {
          buf[i] = array[i] & 255;
        }
        return buf;
      }
      function fromArrayView(arrayView) {
        if (isInstance(arrayView, Uint8Array)) {
          const copy = new Uint8Array(arrayView);
          return fromArrayBuffer(copy.buffer, copy.byteOffset, copy.byteLength);
        }
        return fromArrayLike(arrayView);
      }
      function fromArrayBuffer(array, byteOffset, length) {
        if (byteOffset < 0 || array.byteLength < byteOffset) {
          throw new RangeError('"offset" is outside of buffer bounds');
        }
        if (array.byteLength < byteOffset + (length || 0)) {
          throw new RangeError('"length" is outside of buffer bounds');
        }
        let buf;
        if (byteOffset === void 0 && length === void 0) {
          buf = new Uint8Array(array);
        } else if (length === void 0) {
          buf = new Uint8Array(array, byteOffset);
        } else {
          buf = new Uint8Array(array, byteOffset, length);
        }
        Object.setPrototypeOf(buf, Buffer11.prototype);
        return buf;
      }
      function fromObject(obj) {
        if (Buffer11.isBuffer(obj)) {
          const len = checked(obj.length) | 0;
          const buf = createBuffer(len);
          if (buf.length === 0) {
            return buf;
          }
          obj.copy(buf, 0, 0, len);
          return buf;
        }
        if (obj.length !== void 0) {
          if (typeof obj.length !== "number" || numberIsNaN(obj.length)) {
            return createBuffer(0);
          }
          return fromArrayLike(obj);
        }
        if (obj.type === "Buffer" && Array.isArray(obj.data)) {
          return fromArrayLike(obj.data);
        }
      }
      function checked(length) {
        if (length >= K_MAX_LENGTH) {
          throw new RangeError("Attempt to allocate Buffer larger than maximum size: 0x" + K_MAX_LENGTH.toString(16) + " bytes");
        }
        return length | 0;
      }
      function SlowBuffer(length) {
        if (+length != length) {
          length = 0;
        }
        return Buffer11.alloc(+length);
      }
      Buffer11.isBuffer = function isBuffer2(b) {
        return b != null && b._isBuffer === true && b !== Buffer11.prototype;
      };
      Buffer11.compare = function compare3(a, b) {
        if (isInstance(a, Uint8Array)) a = Buffer11.from(a, a.offset, a.byteLength);
        if (isInstance(b, Uint8Array)) b = Buffer11.from(b, b.offset, b.byteLength);
        if (!Buffer11.isBuffer(a) || !Buffer11.isBuffer(b)) {
          throw new TypeError(
            'The "buf1", "buf2" arguments must be one of type Buffer or Uint8Array'
          );
        }
        if (a === b) return 0;
        let x = a.length;
        let y = b.length;
        for (let i = 0, len = Math.min(x, y); i < len; ++i) {
          if (a[i] !== b[i]) {
            x = a[i];
            y = b[i];
            break;
          }
        }
        if (x < y) return -1;
        if (y < x) return 1;
        return 0;
      };
      Buffer11.isEncoding = function isEncoding(encoding) {
        switch (String(encoding).toLowerCase()) {
          case "hex":
          case "utf8":
          case "utf-8":
          case "ascii":
          case "latin1":
          case "binary":
          case "base64":
          case "ucs2":
          case "ucs-2":
          case "utf16le":
          case "utf-16le":
            return true;
          default:
            return false;
        }
      };
      Buffer11.concat = function concat2(list, length) {
        if (!Array.isArray(list)) {
          throw new TypeError('"list" argument must be an Array of Buffers');
        }
        if (list.length === 0) {
          return Buffer11.alloc(0);
        }
        let i;
        if (length === void 0) {
          length = 0;
          for (i = 0; i < list.length; ++i) {
            length += list[i].length;
          }
        }
        const buffer2 = Buffer11.allocUnsafe(length);
        let pos = 0;
        for (i = 0; i < list.length; ++i) {
          let buf = list[i];
          if (isInstance(buf, Uint8Array)) {
            if (pos + buf.length > buffer2.length) {
              if (!Buffer11.isBuffer(buf)) buf = Buffer11.from(buf);
              buf.copy(buffer2, pos);
            } else {
              Uint8Array.prototype.set.call(
                buffer2,
                buf,
                pos
              );
            }
          } else if (!Buffer11.isBuffer(buf)) {
            throw new TypeError('"list" argument must be an Array of Buffers');
          } else {
            buf.copy(buffer2, pos);
          }
          pos += buf.length;
        }
        return buffer2;
      };
      function byteLength(string, encoding) {
        if (Buffer11.isBuffer(string)) {
          return string.length;
        }
        if (ArrayBuffer.isView(string) || isInstance(string, ArrayBuffer)) {
          return string.byteLength;
        }
        if (typeof string !== "string") {
          throw new TypeError(
            'The "string" argument must be one of type string, Buffer, or ArrayBuffer. Received type ' + typeof string
          );
        }
        const len = string.length;
        const mustMatch = arguments.length > 2 && arguments[2] === true;
        if (!mustMatch && len === 0) return 0;
        let loweredCase = false;
        for (; ; ) {
          switch (encoding) {
            case "ascii":
            case "latin1":
            case "binary":
              return len;
            case "utf8":
            case "utf-8":
              return utf8ToBytes3(string).length;
            case "ucs2":
            case "ucs-2":
            case "utf16le":
            case "utf-16le":
              return len * 2;
            case "hex":
              return len >>> 1;
            case "base64":
              return base64ToBytes(string).length;
            default:
              if (loweredCase) {
                return mustMatch ? -1 : utf8ToBytes3(string).length;
              }
              encoding = ("" + encoding).toLowerCase();
              loweredCase = true;
          }
        }
      }
      Buffer11.byteLength = byteLength;
      function slowToString(encoding, start, end) {
        let loweredCase = false;
        if (start === void 0 || start < 0) {
          start = 0;
        }
        if (start > this.length) {
          return "";
        }
        if (end === void 0 || end > this.length) {
          end = this.length;
        }
        if (end <= 0) {
          return "";
        }
        end >>>= 0;
        start >>>= 0;
        if (end <= start) {
          return "";
        }
        if (!encoding) encoding = "utf8";
        while (true) {
          switch (encoding) {
            case "hex":
              return hexSlice(this, start, end);
            case "utf8":
            case "utf-8":
              return utf8Slice(this, start, end);
            case "ascii":
              return asciiSlice(this, start, end);
            case "latin1":
            case "binary":
              return latin1Slice(this, start, end);
            case "base64":
              return base64Slice(this, start, end);
            case "ucs2":
            case "ucs-2":
            case "utf16le":
            case "utf-16le":
              return utf16leSlice(this, start, end);
            default:
              if (loweredCase) throw new TypeError("Unknown encoding: " + encoding);
              encoding = (encoding + "").toLowerCase();
              loweredCase = true;
          }
        }
      }
      Buffer11.prototype._isBuffer = true;
      function swap(b, n, m) {
        const i = b[n];
        b[n] = b[m];
        b[m] = i;
      }
      Buffer11.prototype.swap16 = function swap16() {
        const len = this.length;
        if (len % 2 !== 0) {
          throw new RangeError("Buffer size must be a multiple of 16-bits");
        }
        for (let i = 0; i < len; i += 2) {
          swap(this, i, i + 1);
        }
        return this;
      };
      Buffer11.prototype.swap32 = function swap32() {
        const len = this.length;
        if (len % 4 !== 0) {
          throw new RangeError("Buffer size must be a multiple of 32-bits");
        }
        for (let i = 0; i < len; i += 4) {
          swap(this, i, i + 3);
          swap(this, i + 1, i + 2);
        }
        return this;
      };
      Buffer11.prototype.swap64 = function swap64() {
        const len = this.length;
        if (len % 8 !== 0) {
          throw new RangeError("Buffer size must be a multiple of 64-bits");
        }
        for (let i = 0; i < len; i += 8) {
          swap(this, i, i + 7);
          swap(this, i + 1, i + 6);
          swap(this, i + 2, i + 5);
          swap(this, i + 3, i + 4);
        }
        return this;
      };
      Buffer11.prototype.toString = function toString() {
        const length = this.length;
        if (length === 0) return "";
        if (arguments.length === 0) return utf8Slice(this, 0, length);
        return slowToString.apply(this, arguments);
      };
      Buffer11.prototype.toLocaleString = Buffer11.prototype.toString;
      Buffer11.prototype.equals = function equals(b) {
        if (!Buffer11.isBuffer(b)) throw new TypeError("Argument must be a Buffer");
        if (this === b) return true;
        return Buffer11.compare(this, b) === 0;
      };
      Buffer11.prototype.inspect = function inspect() {
        let str = "";
        const max = exports.INSPECT_MAX_BYTES;
        str = this.toString("hex", 0, max).replace(/(.{2})/g, "$1 ").trim();
        if (this.length > max) str += " ... ";
        return "<Buffer " + str + ">";
      };
      if (customInspectSymbol) {
        Buffer11.prototype[customInspectSymbol] = Buffer11.prototype.inspect;
      }
      Buffer11.prototype.compare = function compare3(target, start, end, thisStart, thisEnd) {
        if (isInstance(target, Uint8Array)) {
          target = Buffer11.from(target, target.offset, target.byteLength);
        }
        if (!Buffer11.isBuffer(target)) {
          throw new TypeError(
            'The "target" argument must be one of type Buffer or Uint8Array. Received type ' + typeof target
          );
        }
        if (start === void 0) {
          start = 0;
        }
        if (end === void 0) {
          end = target ? target.length : 0;
        }
        if (thisStart === void 0) {
          thisStart = 0;
        }
        if (thisEnd === void 0) {
          thisEnd = this.length;
        }
        if (start < 0 || end > target.length || thisStart < 0 || thisEnd > this.length) {
          throw new RangeError("out of range index");
        }
        if (thisStart >= thisEnd && start >= end) {
          return 0;
        }
        if (thisStart >= thisEnd) {
          return -1;
        }
        if (start >= end) {
          return 1;
        }
        start >>>= 0;
        end >>>= 0;
        thisStart >>>= 0;
        thisEnd >>>= 0;
        if (this === target) return 0;
        let x = thisEnd - thisStart;
        let y = end - start;
        const len = Math.min(x, y);
        const thisCopy = this.slice(thisStart, thisEnd);
        const targetCopy = target.slice(start, end);
        for (let i = 0; i < len; ++i) {
          if (thisCopy[i] !== targetCopy[i]) {
            x = thisCopy[i];
            y = targetCopy[i];
            break;
          }
        }
        if (x < y) return -1;
        if (y < x) return 1;
        return 0;
      };
      function bidirectionalIndexOf(buffer2, val, byteOffset, encoding, dir) {
        if (buffer2.length === 0) return -1;
        if (typeof byteOffset === "string") {
          encoding = byteOffset;
          byteOffset = 0;
        } else if (byteOffset > 2147483647) {
          byteOffset = 2147483647;
        } else if (byteOffset < -2147483648) {
          byteOffset = -2147483648;
        }
        byteOffset = +byteOffset;
        if (numberIsNaN(byteOffset)) {
          byteOffset = dir ? 0 : buffer2.length - 1;
        }
        if (byteOffset < 0) byteOffset = buffer2.length + byteOffset;
        if (byteOffset >= buffer2.length) {
          if (dir) return -1;
          else byteOffset = buffer2.length - 1;
        } else if (byteOffset < 0) {
          if (dir) byteOffset = 0;
          else return -1;
        }
        if (typeof val === "string") {
          val = Buffer11.from(val, encoding);
        }
        if (Buffer11.isBuffer(val)) {
          if (val.length === 0) {
            return -1;
          }
          return arrayIndexOf(buffer2, val, byteOffset, encoding, dir);
        } else if (typeof val === "number") {
          val = val & 255;
          if (typeof Uint8Array.prototype.indexOf === "function") {
            if (dir) {
              return Uint8Array.prototype.indexOf.call(buffer2, val, byteOffset);
            } else {
              return Uint8Array.prototype.lastIndexOf.call(buffer2, val, byteOffset);
            }
          }
          return arrayIndexOf(buffer2, [val], byteOffset, encoding, dir);
        }
        throw new TypeError("val must be string, number or Buffer");
      }
      function arrayIndexOf(arr, val, byteOffset, encoding, dir) {
        let indexSize = 1;
        let arrLength = arr.length;
        let valLength = val.length;
        if (encoding !== void 0) {
          encoding = String(encoding).toLowerCase();
          if (encoding === "ucs2" || encoding === "ucs-2" || encoding === "utf16le" || encoding === "utf-16le") {
            if (arr.length < 2 || val.length < 2) {
              return -1;
            }
            indexSize = 2;
            arrLength /= 2;
            valLength /= 2;
            byteOffset /= 2;
          }
        }
        function read(buf, i2) {
          if (indexSize === 1) {
            return buf[i2];
          } else {
            return buf.readUInt16BE(i2 * indexSize);
          }
        }
        let i;
        if (dir) {
          let foundIndex = -1;
          for (i = byteOffset; i < arrLength; i++) {
            if (read(arr, i) === read(val, foundIndex === -1 ? 0 : i - foundIndex)) {
              if (foundIndex === -1) foundIndex = i;
              if (i - foundIndex + 1 === valLength) return foundIndex * indexSize;
            } else {
              if (foundIndex !== -1) i -= i - foundIndex;
              foundIndex = -1;
            }
          }
        } else {
          if (byteOffset + valLength > arrLength) byteOffset = arrLength - valLength;
          for (i = byteOffset; i >= 0; i--) {
            let found = true;
            for (let j = 0; j < valLength; j++) {
              if (read(arr, i + j) !== read(val, j)) {
                found = false;
                break;
              }
            }
            if (found) return i;
          }
        }
        return -1;
      }
      Buffer11.prototype.includes = function includes(val, byteOffset, encoding) {
        return this.indexOf(val, byteOffset, encoding) !== -1;
      };
      Buffer11.prototype.indexOf = function indexOf(val, byteOffset, encoding) {
        return bidirectionalIndexOf(this, val, byteOffset, encoding, true);
      };
      Buffer11.prototype.lastIndexOf = function lastIndexOf(val, byteOffset, encoding) {
        return bidirectionalIndexOf(this, val, byteOffset, encoding, false);
      };
      function hexWrite(buf, string, offset, length) {
        offset = Number(offset) || 0;
        const remaining = buf.length - offset;
        if (!length) {
          length = remaining;
        } else {
          length = Number(length);
          if (length > remaining) {
            length = remaining;
          }
        }
        const strLen = string.length;
        if (length > strLen / 2) {
          length = strLen / 2;
        }
        let i;
        for (i = 0; i < length; ++i) {
          const parsed = parseInt(string.substr(i * 2, 2), 16);
          if (numberIsNaN(parsed)) return i;
          buf[offset + i] = parsed;
        }
        return i;
      }
      function utf8Write(buf, string, offset, length) {
        return blitBuffer(utf8ToBytes3(string, buf.length - offset), buf, offset, length);
      }
      function asciiWrite(buf, string, offset, length) {
        return blitBuffer(asciiToBytes(string), buf, offset, length);
      }
      function base64Write(buf, string, offset, length) {
        return blitBuffer(base64ToBytes(string), buf, offset, length);
      }
      function ucs2Write(buf, string, offset, length) {
        return blitBuffer(utf16leToBytes(string, buf.length - offset), buf, offset, length);
      }
      Buffer11.prototype.write = function write(string, offset, length, encoding) {
        if (offset === void 0) {
          encoding = "utf8";
          length = this.length;
          offset = 0;
        } else if (length === void 0 && typeof offset === "string") {
          encoding = offset;
          length = this.length;
          offset = 0;
        } else if (isFinite(offset)) {
          offset = offset >>> 0;
          if (isFinite(length)) {
            length = length >>> 0;
            if (encoding === void 0) encoding = "utf8";
          } else {
            encoding = length;
            length = void 0;
          }
        } else {
          throw new Error(
            "Buffer.write(string, encoding, offset[, length]) is no longer supported"
          );
        }
        const remaining = this.length - offset;
        if (length === void 0 || length > remaining) length = remaining;
        if (string.length > 0 && (length < 0 || offset < 0) || offset > this.length) {
          throw new RangeError("Attempt to write outside buffer bounds");
        }
        if (!encoding) encoding = "utf8";
        let loweredCase = false;
        for (; ; ) {
          switch (encoding) {
            case "hex":
              return hexWrite(this, string, offset, length);
            case "utf8":
            case "utf-8":
              return utf8Write(this, string, offset, length);
            case "ascii":
            case "latin1":
            case "binary":
              return asciiWrite(this, string, offset, length);
            case "base64":
              return base64Write(this, string, offset, length);
            case "ucs2":
            case "ucs-2":
            case "utf16le":
            case "utf-16le":
              return ucs2Write(this, string, offset, length);
            default:
              if (loweredCase) throw new TypeError("Unknown encoding: " + encoding);
              encoding = ("" + encoding).toLowerCase();
              loweredCase = true;
          }
        }
      };
      Buffer11.prototype.toJSON = function toJSON() {
        return {
          type: "Buffer",
          data: Array.prototype.slice.call(this._arr || this, 0)
        };
      };
      function base64Slice(buf, start, end) {
        if (start === 0 && end === buf.length) {
          return base64.fromByteArray(buf);
        } else {
          return base64.fromByteArray(buf.slice(start, end));
        }
      }
      function utf8Slice(buf, start, end) {
        end = Math.min(buf.length, end);
        const res = [];
        let i = start;
        while (i < end) {
          const firstByte = buf[i];
          let codePoint = null;
          let bytesPerSequence = firstByte > 239 ? 4 : firstByte > 223 ? 3 : firstByte > 191 ? 2 : 1;
          if (i + bytesPerSequence <= end) {
            let secondByte, thirdByte, fourthByte, tempCodePoint;
            switch (bytesPerSequence) {
              case 1:
                if (firstByte < 128) {
                  codePoint = firstByte;
                }
                break;
              case 2:
                secondByte = buf[i + 1];
                if ((secondByte & 192) === 128) {
                  tempCodePoint = (firstByte & 31) << 6 | secondByte & 63;
                  if (tempCodePoint > 127) {
                    codePoint = tempCodePoint;
                  }
                }
                break;
              case 3:
                secondByte = buf[i + 1];
                thirdByte = buf[i + 2];
                if ((secondByte & 192) === 128 && (thirdByte & 192) === 128) {
                  tempCodePoint = (firstByte & 15) << 12 | (secondByte & 63) << 6 | thirdByte & 63;
                  if (tempCodePoint > 2047 && (tempCodePoint < 55296 || tempCodePoint > 57343)) {
                    codePoint = tempCodePoint;
                  }
                }
                break;
              case 4:
                secondByte = buf[i + 1];
                thirdByte = buf[i + 2];
                fourthByte = buf[i + 3];
                if ((secondByte & 192) === 128 && (thirdByte & 192) === 128 && (fourthByte & 192) === 128) {
                  tempCodePoint = (firstByte & 15) << 18 | (secondByte & 63) << 12 | (thirdByte & 63) << 6 | fourthByte & 63;
                  if (tempCodePoint > 65535 && tempCodePoint < 1114112) {
                    codePoint = tempCodePoint;
                  }
                }
            }
          }
          if (codePoint === null) {
            codePoint = 65533;
            bytesPerSequence = 1;
          } else if (codePoint > 65535) {
            codePoint -= 65536;
            res.push(codePoint >>> 10 & 1023 | 55296);
            codePoint = 56320 | codePoint & 1023;
          }
          res.push(codePoint);
          i += bytesPerSequence;
        }
        return decodeCodePointsArray(res);
      }
      var MAX_ARGUMENTS_LENGTH = 4096;
      function decodeCodePointsArray(codePoints) {
        const len = codePoints.length;
        if (len <= MAX_ARGUMENTS_LENGTH) {
          return String.fromCharCode.apply(String, codePoints);
        }
        let res = "";
        let i = 0;
        while (i < len) {
          res += String.fromCharCode.apply(
            String,
            codePoints.slice(i, i += MAX_ARGUMENTS_LENGTH)
          );
        }
        return res;
      }
      function asciiSlice(buf, start, end) {
        let ret = "";
        end = Math.min(buf.length, end);
        for (let i = start; i < end; ++i) {
          ret += String.fromCharCode(buf[i] & 127);
        }
        return ret;
      }
      function latin1Slice(buf, start, end) {
        let ret = "";
        end = Math.min(buf.length, end);
        for (let i = start; i < end; ++i) {
          ret += String.fromCharCode(buf[i]);
        }
        return ret;
      }
      function hexSlice(buf, start, end) {
        const len = buf.length;
        if (!start || start < 0) start = 0;
        if (!end || end < 0 || end > len) end = len;
        let out = "";
        for (let i = start; i < end; ++i) {
          out += hexSliceLookupTable[buf[i]];
        }
        return out;
      }
      function utf16leSlice(buf, start, end) {
        const bytes = buf.slice(start, end);
        let res = "";
        for (let i = 0; i < bytes.length - 1; i += 2) {
          res += String.fromCharCode(bytes[i] + bytes[i + 1] * 256);
        }
        return res;
      }
      Buffer11.prototype.slice = function slice2(start, end) {
        const len = this.length;
        start = ~~start;
        end = end === void 0 ? len : ~~end;
        if (start < 0) {
          start += len;
          if (start < 0) start = 0;
        } else if (start > len) {
          start = len;
        }
        if (end < 0) {
          end += len;
          if (end < 0) end = 0;
        } else if (end > len) {
          end = len;
        }
        if (end < start) end = start;
        const newBuf = this.subarray(start, end);
        Object.setPrototypeOf(newBuf, Buffer11.prototype);
        return newBuf;
      };
      function checkOffset(offset, ext, length) {
        if (offset % 1 !== 0 || offset < 0) throw new RangeError("offset is not uint");
        if (offset + ext > length) throw new RangeError("Trying to access beyond buffer length");
      }
      Buffer11.prototype.readUintLE = Buffer11.prototype.readUIntLE = function readUIntLE(offset, byteLength2, noAssert) {
        offset = offset >>> 0;
        byteLength2 = byteLength2 >>> 0;
        if (!noAssert) checkOffset(offset, byteLength2, this.length);
        let val = this[offset];
        let mul = 1;
        let i = 0;
        while (++i < byteLength2 && (mul *= 256)) {
          val += this[offset + i] * mul;
        }
        return val;
      };
      Buffer11.prototype.readUintBE = Buffer11.prototype.readUIntBE = function readUIntBE(offset, byteLength2, noAssert) {
        offset = offset >>> 0;
        byteLength2 = byteLength2 >>> 0;
        if (!noAssert) {
          checkOffset(offset, byteLength2, this.length);
        }
        let val = this[offset + --byteLength2];
        let mul = 1;
        while (byteLength2 > 0 && (mul *= 256)) {
          val += this[offset + --byteLength2] * mul;
        }
        return val;
      };
      Buffer11.prototype.readUint8 = Buffer11.prototype.readUInt8 = function readUInt8(offset, noAssert) {
        offset = offset >>> 0;
        if (!noAssert) checkOffset(offset, 1, this.length);
        return this[offset];
      };
      Buffer11.prototype.readUint16LE = Buffer11.prototype.readUInt16LE = function readUInt16LE(offset, noAssert) {
        offset = offset >>> 0;
        if (!noAssert) checkOffset(offset, 2, this.length);
        return this[offset] | this[offset + 1] << 8;
      };
      Buffer11.prototype.readUint16BE = Buffer11.prototype.readUInt16BE = function readUInt16BE(offset, noAssert) {
        offset = offset >>> 0;
        if (!noAssert) checkOffset(offset, 2, this.length);
        return this[offset] << 8 | this[offset + 1];
      };
      Buffer11.prototype.readUint32LE = Buffer11.prototype.readUInt32LE = function readUInt32LE(offset, noAssert) {
        offset = offset >>> 0;
        if (!noAssert) checkOffset(offset, 4, this.length);
        return (this[offset] | this[offset + 1] << 8 | this[offset + 2] << 16) + this[offset + 3] * 16777216;
      };
      Buffer11.prototype.readUint32BE = Buffer11.prototype.readUInt32BE = function readUInt32BE(offset, noAssert) {
        offset = offset >>> 0;
        if (!noAssert) checkOffset(offset, 4, this.length);
        return this[offset] * 16777216 + (this[offset + 1] << 16 | this[offset + 2] << 8 | this[offset + 3]);
      };
      Buffer11.prototype.readBigUInt64LE = defineBigIntMethod(function readBigUInt64LE(offset) {
        offset = offset >>> 0;
        validateNumber(offset, "offset");
        const first = this[offset];
        const last = this[offset + 7];
        if (first === void 0 || last === void 0) {
          boundsError(offset, this.length - 8);
        }
        const lo = first + this[++offset] * 2 ** 8 + this[++offset] * 2 ** 16 + this[++offset] * 2 ** 24;
        const hi = this[++offset] + this[++offset] * 2 ** 8 + this[++offset] * 2 ** 16 + last * 2 ** 24;
        return BigInt(lo) + (BigInt(hi) << BigInt(32));
      });
      Buffer11.prototype.readBigUInt64BE = defineBigIntMethod(function readBigUInt64BE(offset) {
        offset = offset >>> 0;
        validateNumber(offset, "offset");
        const first = this[offset];
        const last = this[offset + 7];
        if (first === void 0 || last === void 0) {
          boundsError(offset, this.length - 8);
        }
        const hi = first * 2 ** 24 + this[++offset] * 2 ** 16 + this[++offset] * 2 ** 8 + this[++offset];
        const lo = this[++offset] * 2 ** 24 + this[++offset] * 2 ** 16 + this[++offset] * 2 ** 8 + last;
        return (BigInt(hi) << BigInt(32)) + BigInt(lo);
      });
      Buffer11.prototype.readIntLE = function readIntLE(offset, byteLength2, noAssert) {
        offset = offset >>> 0;
        byteLength2 = byteLength2 >>> 0;
        if (!noAssert) checkOffset(offset, byteLength2, this.length);
        let val = this[offset];
        let mul = 1;
        let i = 0;
        while (++i < byteLength2 && (mul *= 256)) {
          val += this[offset + i] * mul;
        }
        mul *= 128;
        if (val >= mul) val -= Math.pow(2, 8 * byteLength2);
        return val;
      };
      Buffer11.prototype.readIntBE = function readIntBE(offset, byteLength2, noAssert) {
        offset = offset >>> 0;
        byteLength2 = byteLength2 >>> 0;
        if (!noAssert) checkOffset(offset, byteLength2, this.length);
        let i = byteLength2;
        let mul = 1;
        let val = this[offset + --i];
        while (i > 0 && (mul *= 256)) {
          val += this[offset + --i] * mul;
        }
        mul *= 128;
        if (val >= mul) val -= Math.pow(2, 8 * byteLength2);
        return val;
      };
      Buffer11.prototype.readInt8 = function readInt8(offset, noAssert) {
        offset = offset >>> 0;
        if (!noAssert) checkOffset(offset, 1, this.length);
        if (!(this[offset] & 128)) return this[offset];
        return (255 - this[offset] + 1) * -1;
      };
      Buffer11.prototype.readInt16LE = function readInt16LE(offset, noAssert) {
        offset = offset >>> 0;
        if (!noAssert) checkOffset(offset, 2, this.length);
        const val = this[offset] | this[offset + 1] << 8;
        return val & 32768 ? val | 4294901760 : val;
      };
      Buffer11.prototype.readInt16BE = function readInt16BE(offset, noAssert) {
        offset = offset >>> 0;
        if (!noAssert) checkOffset(offset, 2, this.length);
        const val = this[offset + 1] | this[offset] << 8;
        return val & 32768 ? val | 4294901760 : val;
      };
      Buffer11.prototype.readInt32LE = function readInt32LE(offset, noAssert) {
        offset = offset >>> 0;
        if (!noAssert) checkOffset(offset, 4, this.length);
        return this[offset] | this[offset + 1] << 8 | this[offset + 2] << 16 | this[offset + 3] << 24;
      };
      Buffer11.prototype.readInt32BE = function readInt32BE(offset, noAssert) {
        offset = offset >>> 0;
        if (!noAssert) checkOffset(offset, 4, this.length);
        return this[offset] << 24 | this[offset + 1] << 16 | this[offset + 2] << 8 | this[offset + 3];
      };
      Buffer11.prototype.readBigInt64LE = defineBigIntMethod(function readBigInt64LE(offset) {
        offset = offset >>> 0;
        validateNumber(offset, "offset");
        const first = this[offset];
        const last = this[offset + 7];
        if (first === void 0 || last === void 0) {
          boundsError(offset, this.length - 8);
        }
        const val = this[offset + 4] + this[offset + 5] * 2 ** 8 + this[offset + 6] * 2 ** 16 + (last << 24);
        return (BigInt(val) << BigInt(32)) + BigInt(first + this[++offset] * 2 ** 8 + this[++offset] * 2 ** 16 + this[++offset] * 2 ** 24);
      });
      Buffer11.prototype.readBigInt64BE = defineBigIntMethod(function readBigInt64BE(offset) {
        offset = offset >>> 0;
        validateNumber(offset, "offset");
        const first = this[offset];
        const last = this[offset + 7];
        if (first === void 0 || last === void 0) {
          boundsError(offset, this.length - 8);
        }
        const val = (first << 24) + // Overflow
        this[++offset] * 2 ** 16 + this[++offset] * 2 ** 8 + this[++offset];
        return (BigInt(val) << BigInt(32)) + BigInt(this[++offset] * 2 ** 24 + this[++offset] * 2 ** 16 + this[++offset] * 2 ** 8 + last);
      });
      Buffer11.prototype.readFloatLE = function readFloatLE(offset, noAssert) {
        offset = offset >>> 0;
        if (!noAssert) checkOffset(offset, 4, this.length);
        return ieee754.read(this, offset, true, 23, 4);
      };
      Buffer11.prototype.readFloatBE = function readFloatBE(offset, noAssert) {
        offset = offset >>> 0;
        if (!noAssert) checkOffset(offset, 4, this.length);
        return ieee754.read(this, offset, false, 23, 4);
      };
      Buffer11.prototype.readDoubleLE = function readDoubleLE(offset, noAssert) {
        offset = offset >>> 0;
        if (!noAssert) checkOffset(offset, 8, this.length);
        return ieee754.read(this, offset, true, 52, 8);
      };
      Buffer11.prototype.readDoubleBE = function readDoubleBE(offset, noAssert) {
        offset = offset >>> 0;
        if (!noAssert) checkOffset(offset, 8, this.length);
        return ieee754.read(this, offset, false, 52, 8);
      };
      function checkInt(buf, value, offset, ext, max, min) {
        if (!Buffer11.isBuffer(buf)) throw new TypeError('"buffer" argument must be a Buffer instance');
        if (value > max || value < min) throw new RangeError('"value" argument is out of bounds');
        if (offset + ext > buf.length) throw new RangeError("Index out of range");
      }
      Buffer11.prototype.writeUintLE = Buffer11.prototype.writeUIntLE = function writeUIntLE(value, offset, byteLength2, noAssert) {
        value = +value;
        offset = offset >>> 0;
        byteLength2 = byteLength2 >>> 0;
        if (!noAssert) {
          const maxBytes = Math.pow(2, 8 * byteLength2) - 1;
          checkInt(this, value, offset, byteLength2, maxBytes, 0);
        }
        let mul = 1;
        let i = 0;
        this[offset] = value & 255;
        while (++i < byteLength2 && (mul *= 256)) {
          this[offset + i] = value / mul & 255;
        }
        return offset + byteLength2;
      };
      Buffer11.prototype.writeUintBE = Buffer11.prototype.writeUIntBE = function writeUIntBE(value, offset, byteLength2, noAssert) {
        value = +value;
        offset = offset >>> 0;
        byteLength2 = byteLength2 >>> 0;
        if (!noAssert) {
          const maxBytes = Math.pow(2, 8 * byteLength2) - 1;
          checkInt(this, value, offset, byteLength2, maxBytes, 0);
        }
        let i = byteLength2 - 1;
        let mul = 1;
        this[offset + i] = value & 255;
        while (--i >= 0 && (mul *= 256)) {
          this[offset + i] = value / mul & 255;
        }
        return offset + byteLength2;
      };
      Buffer11.prototype.writeUint8 = Buffer11.prototype.writeUInt8 = function writeUInt8(value, offset, noAssert) {
        value = +value;
        offset = offset >>> 0;
        if (!noAssert) checkInt(this, value, offset, 1, 255, 0);
        this[offset] = value & 255;
        return offset + 1;
      };
      Buffer11.prototype.writeUint16LE = Buffer11.prototype.writeUInt16LE = function writeUInt16LE(value, offset, noAssert) {
        value = +value;
        offset = offset >>> 0;
        if (!noAssert) checkInt(this, value, offset, 2, 65535, 0);
        this[offset] = value & 255;
        this[offset + 1] = value >>> 8;
        return offset + 2;
      };
      Buffer11.prototype.writeUint16BE = Buffer11.prototype.writeUInt16BE = function writeUInt16BE(value, offset, noAssert) {
        value = +value;
        offset = offset >>> 0;
        if (!noAssert) checkInt(this, value, offset, 2, 65535, 0);
        this[offset] = value >>> 8;
        this[offset + 1] = value & 255;
        return offset + 2;
      };
      Buffer11.prototype.writeUint32LE = Buffer11.prototype.writeUInt32LE = function writeUInt32LE(value, offset, noAssert) {
        value = +value;
        offset = offset >>> 0;
        if (!noAssert) checkInt(this, value, offset, 4, 4294967295, 0);
        this[offset + 3] = value >>> 24;
        this[offset + 2] = value >>> 16;
        this[offset + 1] = value >>> 8;
        this[offset] = value & 255;
        return offset + 4;
      };
      Buffer11.prototype.writeUint32BE = Buffer11.prototype.writeUInt32BE = function writeUInt32BE(value, offset, noAssert) {
        value = +value;
        offset = offset >>> 0;
        if (!noAssert) checkInt(this, value, offset, 4, 4294967295, 0);
        this[offset] = value >>> 24;
        this[offset + 1] = value >>> 16;
        this[offset + 2] = value >>> 8;
        this[offset + 3] = value & 255;
        return offset + 4;
      };
      function wrtBigUInt64LE(buf, value, offset, min, max) {
        checkIntBI(value, min, max, buf, offset, 7);
        let lo = Number(value & BigInt(4294967295));
        buf[offset++] = lo;
        lo = lo >> 8;
        buf[offset++] = lo;
        lo = lo >> 8;
        buf[offset++] = lo;
        lo = lo >> 8;
        buf[offset++] = lo;
        let hi = Number(value >> BigInt(32) & BigInt(4294967295));
        buf[offset++] = hi;
        hi = hi >> 8;
        buf[offset++] = hi;
        hi = hi >> 8;
        buf[offset++] = hi;
        hi = hi >> 8;
        buf[offset++] = hi;
        return offset;
      }
      function wrtBigUInt64BE(buf, value, offset, min, max) {
        checkIntBI(value, min, max, buf, offset, 7);
        let lo = Number(value & BigInt(4294967295));
        buf[offset + 7] = lo;
        lo = lo >> 8;
        buf[offset + 6] = lo;
        lo = lo >> 8;
        buf[offset + 5] = lo;
        lo = lo >> 8;
        buf[offset + 4] = lo;
        let hi = Number(value >> BigInt(32) & BigInt(4294967295));
        buf[offset + 3] = hi;
        hi = hi >> 8;
        buf[offset + 2] = hi;
        hi = hi >> 8;
        buf[offset + 1] = hi;
        hi = hi >> 8;
        buf[offset] = hi;
        return offset + 8;
      }
      Buffer11.prototype.writeBigUInt64LE = defineBigIntMethod(function writeBigUInt64LE(value, offset = 0) {
        return wrtBigUInt64LE(this, value, offset, BigInt(0), BigInt("0xffffffffffffffff"));
      });
      Buffer11.prototype.writeBigUInt64BE = defineBigIntMethod(function writeBigUInt64BE(value, offset = 0) {
        return wrtBigUInt64BE(this, value, offset, BigInt(0), BigInt("0xffffffffffffffff"));
      });
      Buffer11.prototype.writeIntLE = function writeIntLE(value, offset, byteLength2, noAssert) {
        value = +value;
        offset = offset >>> 0;
        if (!noAssert) {
          const limit = Math.pow(2, 8 * byteLength2 - 1);
          checkInt(this, value, offset, byteLength2, limit - 1, -limit);
        }
        let i = 0;
        let mul = 1;
        let sub = 0;
        this[offset] = value & 255;
        while (++i < byteLength2 && (mul *= 256)) {
          if (value < 0 && sub === 0 && this[offset + i - 1] !== 0) {
            sub = 1;
          }
          this[offset + i] = (value / mul >> 0) - sub & 255;
        }
        return offset + byteLength2;
      };
      Buffer11.prototype.writeIntBE = function writeIntBE(value, offset, byteLength2, noAssert) {
        value = +value;
        offset = offset >>> 0;
        if (!noAssert) {
          const limit = Math.pow(2, 8 * byteLength2 - 1);
          checkInt(this, value, offset, byteLength2, limit - 1, -limit);
        }
        let i = byteLength2 - 1;
        let mul = 1;
        let sub = 0;
        this[offset + i] = value & 255;
        while (--i >= 0 && (mul *= 256)) {
          if (value < 0 && sub === 0 && this[offset + i + 1] !== 0) {
            sub = 1;
          }
          this[offset + i] = (value / mul >> 0) - sub & 255;
        }
        return offset + byteLength2;
      };
      Buffer11.prototype.writeInt8 = function writeInt8(value, offset, noAssert) {
        value = +value;
        offset = offset >>> 0;
        if (!noAssert) checkInt(this, value, offset, 1, 127, -128);
        if (value < 0) value = 255 + value + 1;
        this[offset] = value & 255;
        return offset + 1;
      };
      Buffer11.prototype.writeInt16LE = function writeInt16LE(value, offset, noAssert) {
        value = +value;
        offset = offset >>> 0;
        if (!noAssert) checkInt(this, value, offset, 2, 32767, -32768);
        this[offset] = value & 255;
        this[offset + 1] = value >>> 8;
        return offset + 2;
      };
      Buffer11.prototype.writeInt16BE = function writeInt16BE(value, offset, noAssert) {
        value = +value;
        offset = offset >>> 0;
        if (!noAssert) checkInt(this, value, offset, 2, 32767, -32768);
        this[offset] = value >>> 8;
        this[offset + 1] = value & 255;
        return offset + 2;
      };
      Buffer11.prototype.writeInt32LE = function writeInt32LE(value, offset, noAssert) {
        value = +value;
        offset = offset >>> 0;
        if (!noAssert) checkInt(this, value, offset, 4, 2147483647, -2147483648);
        this[offset] = value & 255;
        this[offset + 1] = value >>> 8;
        this[offset + 2] = value >>> 16;
        this[offset + 3] = value >>> 24;
        return offset + 4;
      };
      Buffer11.prototype.writeInt32BE = function writeInt32BE(value, offset, noAssert) {
        value = +value;
        offset = offset >>> 0;
        if (!noAssert) checkInt(this, value, offset, 4, 2147483647, -2147483648);
        if (value < 0) value = 4294967295 + value + 1;
        this[offset] = value >>> 24;
        this[offset + 1] = value >>> 16;
        this[offset + 2] = value >>> 8;
        this[offset + 3] = value & 255;
        return offset + 4;
      };
      Buffer11.prototype.writeBigInt64LE = defineBigIntMethod(function writeBigInt64LE(value, offset = 0) {
        return wrtBigUInt64LE(this, value, offset, -BigInt("0x8000000000000000"), BigInt("0x7fffffffffffffff"));
      });
      Buffer11.prototype.writeBigInt64BE = defineBigIntMethod(function writeBigInt64BE(value, offset = 0) {
        return wrtBigUInt64BE(this, value, offset, -BigInt("0x8000000000000000"), BigInt("0x7fffffffffffffff"));
      });
      function checkIEEE754(buf, value, offset, ext, max, min) {
        if (offset + ext > buf.length) throw new RangeError("Index out of range");
        if (offset < 0) throw new RangeError("Index out of range");
      }
      function writeFloat(buf, value, offset, littleEndian, noAssert) {
        value = +value;
        offset = offset >>> 0;
        if (!noAssert) {
          checkIEEE754(buf, value, offset, 4, 34028234663852886e22, -34028234663852886e22);
        }
        ieee754.write(buf, value, offset, littleEndian, 23, 4);
        return offset + 4;
      }
      Buffer11.prototype.writeFloatLE = function writeFloatLE(value, offset, noAssert) {
        return writeFloat(this, value, offset, true, noAssert);
      };
      Buffer11.prototype.writeFloatBE = function writeFloatBE(value, offset, noAssert) {
        return writeFloat(this, value, offset, false, noAssert);
      };
      function writeDouble(buf, value, offset, littleEndian, noAssert) {
        value = +value;
        offset = offset >>> 0;
        if (!noAssert) {
          checkIEEE754(buf, value, offset, 8, 17976931348623157e292, -17976931348623157e292);
        }
        ieee754.write(buf, value, offset, littleEndian, 52, 8);
        return offset + 8;
      }
      Buffer11.prototype.writeDoubleLE = function writeDoubleLE(value, offset, noAssert) {
        return writeDouble(this, value, offset, true, noAssert);
      };
      Buffer11.prototype.writeDoubleBE = function writeDoubleBE(value, offset, noAssert) {
        return writeDouble(this, value, offset, false, noAssert);
      };
      Buffer11.prototype.copy = function copy(target, targetStart, start, end) {
        if (!Buffer11.isBuffer(target)) throw new TypeError("argument should be a Buffer");
        if (!start) start = 0;
        if (!end && end !== 0) end = this.length;
        if (targetStart >= target.length) targetStart = target.length;
        if (!targetStart) targetStart = 0;
        if (end > 0 && end < start) end = start;
        if (end === start) return 0;
        if (target.length === 0 || this.length === 0) return 0;
        if (targetStart < 0) {
          throw new RangeError("targetStart out of bounds");
        }
        if (start < 0 || start >= this.length) throw new RangeError("Index out of range");
        if (end < 0) throw new RangeError("sourceEnd out of bounds");
        if (end > this.length) end = this.length;
        if (target.length - targetStart < end - start) {
          end = target.length - targetStart + start;
        }
        const len = end - start;
        if (this === target && typeof Uint8Array.prototype.copyWithin === "function") {
          this.copyWithin(targetStart, start, end);
        } else {
          Uint8Array.prototype.set.call(
            target,
            this.subarray(start, end),
            targetStart
          );
        }
        return len;
      };
      Buffer11.prototype.fill = function fill(val, start, end, encoding) {
        if (typeof val === "string") {
          if (typeof start === "string") {
            encoding = start;
            start = 0;
            end = this.length;
          } else if (typeof end === "string") {
            encoding = end;
            end = this.length;
          }
          if (encoding !== void 0 && typeof encoding !== "string") {
            throw new TypeError("encoding must be a string");
          }
          if (typeof encoding === "string" && !Buffer11.isEncoding(encoding)) {
            throw new TypeError("Unknown encoding: " + encoding);
          }
          if (val.length === 1) {
            const code = val.charCodeAt(0);
            if (encoding === "utf8" && code < 128 || encoding === "latin1") {
              val = code;
            }
          }
        } else if (typeof val === "number") {
          val = val & 255;
        } else if (typeof val === "boolean") {
          val = Number(val);
        }
        if (start < 0 || this.length < start || this.length < end) {
          throw new RangeError("Out of range index");
        }
        if (end <= start) {
          return this;
        }
        start = start >>> 0;
        end = end === void 0 ? this.length : end >>> 0;
        if (!val) val = 0;
        let i;
        if (typeof val === "number") {
          for (i = start; i < end; ++i) {
            this[i] = val;
          }
        } else {
          const bytes = Buffer11.isBuffer(val) ? val : Buffer11.from(val, encoding);
          const len = bytes.length;
          if (len === 0) {
            throw new TypeError('The value "' + val + '" is invalid for argument "value"');
          }
          for (i = 0; i < end - start; ++i) {
            this[i + start] = bytes[i % len];
          }
        }
        return this;
      };
      var errors = {};
      function E(sym, getMessage, Base) {
        errors[sym] = class NodeError extends Base {
          constructor() {
            super();
            Object.defineProperty(this, "message", {
              value: getMessage.apply(this, arguments),
              writable: true,
              configurable: true
            });
            this.name = `${this.name} [${sym}]`;
            this.stack;
            delete this.name;
          }
          get code() {
            return sym;
          }
          set code(value) {
            Object.defineProperty(this, "code", {
              configurable: true,
              enumerable: true,
              value,
              writable: true
            });
          }
          toString() {
            return `${this.name} [${sym}]: ${this.message}`;
          }
        };
      }
      E(
        "ERR_BUFFER_OUT_OF_BOUNDS",
        function(name) {
          if (name) {
            return `${name} is outside of buffer bounds`;
          }
          return "Attempt to access memory outside buffer bounds";
        },
        RangeError
      );
      E(
        "ERR_INVALID_ARG_TYPE",
        function(name, actual) {
          return `The "${name}" argument must be of type number. Received type ${typeof actual}`;
        },
        TypeError
      );
      E(
        "ERR_OUT_OF_RANGE",
        function(str, range, input) {
          let msg = `The value of "${str}" is out of range.`;
          let received = input;
          if (Number.isInteger(input) && Math.abs(input) > 2 ** 32) {
            received = addNumericalSeparator(String(input));
          } else if (typeof input === "bigint") {
            received = String(input);
            if (input > BigInt(2) ** BigInt(32) || input < -(BigInt(2) ** BigInt(32))) {
              received = addNumericalSeparator(received);
            }
            received += "n";
          }
          msg += ` It must be ${range}. Received ${received}`;
          return msg;
        },
        RangeError
      );
      function addNumericalSeparator(val) {
        let res = "";
        let i = val.length;
        const start = val[0] === "-" ? 1 : 0;
        for (; i >= start + 4; i -= 3) {
          res = `_${val.slice(i - 3, i)}${res}`;
        }
        return `${val.slice(0, i)}${res}`;
      }
      function checkBounds(buf, offset, byteLength2) {
        validateNumber(offset, "offset");
        if (buf[offset] === void 0 || buf[offset + byteLength2] === void 0) {
          boundsError(offset, buf.length - (byteLength2 + 1));
        }
      }
      function checkIntBI(value, min, max, buf, offset, byteLength2) {
        if (value > max || value < min) {
          const n = typeof min === "bigint" ? "n" : "";
          let range;
          if (byteLength2 > 3) {
            if (min === 0 || min === BigInt(0)) {
              range = `>= 0${n} and < 2${n} ** ${(byteLength2 + 1) * 8}${n}`;
            } else {
              range = `>= -(2${n} ** ${(byteLength2 + 1) * 8 - 1}${n}) and < 2 ** ${(byteLength2 + 1) * 8 - 1}${n}`;
            }
          } else {
            range = `>= ${min}${n} and <= ${max}${n}`;
          }
          throw new errors.ERR_OUT_OF_RANGE("value", range, value);
        }
        checkBounds(buf, offset, byteLength2);
      }
      function validateNumber(value, name) {
        if (typeof value !== "number") {
          throw new errors.ERR_INVALID_ARG_TYPE(name, "number", value);
        }
      }
      function boundsError(value, length, type) {
        if (Math.floor(value) !== value) {
          validateNumber(value, type);
          throw new errors.ERR_OUT_OF_RANGE(type || "offset", "an integer", value);
        }
        if (length < 0) {
          throw new errors.ERR_BUFFER_OUT_OF_BOUNDS();
        }
        throw new errors.ERR_OUT_OF_RANGE(
          type || "offset",
          `>= ${type ? 1 : 0} and <= ${length}`,
          value
        );
      }
      var INVALID_BASE64_RE = /[^+/0-9A-Za-z-_]/g;
      function base64clean(str) {
        str = str.split("=")[0];
        str = str.trim().replace(INVALID_BASE64_RE, "");
        if (str.length < 2) return "";
        while (str.length % 4 !== 0) {
          str = str + "=";
        }
        return str;
      }
      function utf8ToBytes3(string, units) {
        units = units || Infinity;
        let codePoint;
        const length = string.length;
        let leadSurrogate = null;
        const bytes = [];
        for (let i = 0; i < length; ++i) {
          codePoint = string.charCodeAt(i);
          if (codePoint > 55295 && codePoint < 57344) {
            if (!leadSurrogate) {
              if (codePoint > 56319) {
                if ((units -= 3) > -1) bytes.push(239, 191, 189);
                continue;
              } else if (i + 1 === length) {
                if ((units -= 3) > -1) bytes.push(239, 191, 189);
                continue;
              }
              leadSurrogate = codePoint;
              continue;
            }
            if (codePoint < 56320) {
              if ((units -= 3) > -1) bytes.push(239, 191, 189);
              leadSurrogate = codePoint;
              continue;
            }
            codePoint = (leadSurrogate - 55296 << 10 | codePoint - 56320) + 65536;
          } else if (leadSurrogate) {
            if ((units -= 3) > -1) bytes.push(239, 191, 189);
          }
          leadSurrogate = null;
          if (codePoint < 128) {
            if ((units -= 1) < 0) break;
            bytes.push(codePoint);
          } else if (codePoint < 2048) {
            if ((units -= 2) < 0) break;
            bytes.push(
              codePoint >> 6 | 192,
              codePoint & 63 | 128
            );
          } else if (codePoint < 65536) {
            if ((units -= 3) < 0) break;
            bytes.push(
              codePoint >> 12 | 224,
              codePoint >> 6 & 63 | 128,
              codePoint & 63 | 128
            );
          } else if (codePoint < 1114112) {
            if ((units -= 4) < 0) break;
            bytes.push(
              codePoint >> 18 | 240,
              codePoint >> 12 & 63 | 128,
              codePoint >> 6 & 63 | 128,
              codePoint & 63 | 128
            );
          } else {
            throw new Error("Invalid code point");
          }
        }
        return bytes;
      }
      function asciiToBytes(str) {
        const byteArray = [];
        for (let i = 0; i < str.length; ++i) {
          byteArray.push(str.charCodeAt(i) & 255);
        }
        return byteArray;
      }
      function utf16leToBytes(str, units) {
        let c, hi, lo;
        const byteArray = [];
        for (let i = 0; i < str.length; ++i) {
          if ((units -= 2) < 0) break;
          c = str.charCodeAt(i);
          hi = c >> 8;
          lo = c % 256;
          byteArray.push(lo);
          byteArray.push(hi);
        }
        return byteArray;
      }
      function base64ToBytes(str) {
        return base64.toByteArray(base64clean(str));
      }
      function blitBuffer(src, dst, offset, length) {
        let i;
        for (i = 0; i < length; ++i) {
          if (i + offset >= dst.length || i >= src.length) break;
          dst[i + offset] = src[i];
        }
        return i;
      }
      function isInstance(obj, type) {
        return obj instanceof type || obj != null && obj.constructor != null && obj.constructor.name != null && obj.constructor.name === type.name;
      }
      function numberIsNaN(obj) {
        return obj !== obj;
      }
      var hexSliceLookupTable = (function() {
        const alphabet = "0123456789abcdef";
        const table = new Array(256);
        for (let i = 0; i < 16; ++i) {
          const i16 = i * 16;
          for (let j = 0; j < 16; ++j) {
            table[i16 + j] = alphabet[i] + alphabet[j];
          }
        }
        return table;
      })();
      function defineBigIntMethod(fn) {
        return typeof BigInt === "undefined" ? BufferBigIntNotDefined : fn;
      }
      function BufferBigIntNotDefined() {
        throw new Error("BigInt not supported");
      }
    }
  });

  // buffer-shim.js
  var import_buffer;
  var init_buffer_shim = __esm({
    "buffer-shim.js"() {
      import_buffer = __toESM(require_buffer());
    }
  });

  // node_modules/@gandlaf21/bc-ur/dist/lib/es6/errors.js
  var __extends, InvalidSchemeError, InvalidPathLengthError, InvalidTypeError, InvalidSequenceComponentError, InvalidChecksumError;
  var init_errors = __esm({
    "node_modules/@gandlaf21/bc-ur/dist/lib/es6/errors.js"() {
      init_buffer_shim();
      __extends = /* @__PURE__ */ (function() {
        var extendStatics = function(d, b) {
          extendStatics = Object.setPrototypeOf || { __proto__: [] } instanceof Array && function(d2, b2) {
            d2.__proto__ = b2;
          } || function(d2, b2) {
            for (var p in b2) if (Object.prototype.hasOwnProperty.call(b2, p)) d2[p] = b2[p];
          };
          return extendStatics(d, b);
        };
        return function(d, b) {
          extendStatics(d, b);
          function __() {
            this.constructor = d;
          }
          d.prototype = b === null ? Object.create(b) : (__.prototype = b.prototype, new __());
        };
      })();
      InvalidSchemeError = /** @class */
      (function(_super) {
        __extends(InvalidSchemeError2, _super);
        function InvalidSchemeError2() {
          var _this = _super.call(this, "Invalid Scheme") || this;
          _this.name = "InvalidSchemeError";
          return _this;
        }
        return InvalidSchemeError2;
      })(Error);
      InvalidPathLengthError = /** @class */
      (function(_super) {
        __extends(InvalidPathLengthError2, _super);
        function InvalidPathLengthError2() {
          var _this = _super.call(this, "Invalid Path") || this;
          _this.name = "InvalidPathLengthError";
          return _this;
        }
        return InvalidPathLengthError2;
      })(Error);
      InvalidTypeError = /** @class */
      (function(_super) {
        __extends(InvalidTypeError2, _super);
        function InvalidTypeError2() {
          var _this = _super.call(this, "Invalid Type") || this;
          _this.name = "InvalidTypeError";
          return _this;
        }
        return InvalidTypeError2;
      })(Error);
      InvalidSequenceComponentError = /** @class */
      (function(_super) {
        __extends(InvalidSequenceComponentError2, _super);
        function InvalidSequenceComponentError2() {
          var _this = _super.call(this, "Invalid Sequence Component") || this;
          _this.name = "InvalidSequenceComponentError";
          return _this;
        }
        return InvalidSequenceComponentError2;
      })(Error);
      InvalidChecksumError = /** @class */
      (function(_super) {
        __extends(InvalidChecksumError2, _super);
        function InvalidChecksumError2() {
          var _this = _super.call(this, "Invalid Checksum") || this;
          _this.name = "InvalidChecksumError";
          return _this;
        }
        return InvalidChecksumError2;
      })(Error);
    }
  });

  // node_modules/@noble/hashes/esm/utils.js
  function isBytes(a) {
    return a instanceof Uint8Array || ArrayBuffer.isView(a) && a.constructor.name === "Uint8Array";
  }
  function abytes(b, ...lengths) {
    if (!isBytes(b))
      throw new Error("Uint8Array expected");
    if (lengths.length > 0 && !lengths.includes(b.length))
      throw new Error("Uint8Array expected of length " + lengths + ", got length=" + b.length);
  }
  function aexists(instance, checkFinished = true) {
    if (instance.destroyed)
      throw new Error("Hash instance has been destroyed");
    if (checkFinished && instance.finished)
      throw new Error("Hash#digest() has already been called");
  }
  function aoutput(out, instance) {
    abytes(out);
    const min = instance.outputLen;
    if (out.length < min) {
      throw new Error("digestInto() expects output buffer of length at least " + min);
    }
  }
  function clean(...arrays) {
    for (let i = 0; i < arrays.length; i++) {
      arrays[i].fill(0);
    }
  }
  function createView(arr) {
    return new DataView(arr.buffer, arr.byteOffset, arr.byteLength);
  }
  function rotr(word, shift) {
    return word << 32 - shift | word >>> shift;
  }
  function utf8ToBytes(str) {
    if (typeof str !== "string")
      throw new Error("string expected");
    return new Uint8Array(new TextEncoder().encode(str));
  }
  function toBytes(data) {
    if (typeof data === "string")
      data = utf8ToBytes(data);
    abytes(data);
    return data;
  }
  function createHasher(hashCons) {
    const hashC = (msg) => hashCons().update(toBytes(msg)).digest();
    const tmp = hashCons();
    hashC.outputLen = tmp.outputLen;
    hashC.blockLen = tmp.blockLen;
    hashC.create = () => hashCons();
    return hashC;
  }
  var Hash;
  var init_utils = __esm({
    "node_modules/@noble/hashes/esm/utils.js"() {
      init_buffer_shim();
      /*! noble-hashes - MIT License (c) 2022 Paul Miller (paulmillr.com) */
      Hash = class {
      };
    }
  });

  // node_modules/@noble/hashes/esm/_md.js
  function setBigUint64(view, byteOffset, value, isLE) {
    if (typeof view.setBigUint64 === "function")
      return view.setBigUint64(byteOffset, value, isLE);
    const _32n = BigInt(32);
    const _u32_max = BigInt(4294967295);
    const wh = Number(value >> _32n & _u32_max);
    const wl = Number(value & _u32_max);
    const h = isLE ? 4 : 0;
    const l = isLE ? 0 : 4;
    view.setUint32(byteOffset + h, wh, isLE);
    view.setUint32(byteOffset + l, wl, isLE);
  }
  function Chi(a, b, c) {
    return a & b ^ ~a & c;
  }
  function Maj(a, b, c) {
    return a & b ^ a & c ^ b & c;
  }
  var HashMD, SHA256_IV;
  var init_md = __esm({
    "node_modules/@noble/hashes/esm/_md.js"() {
      init_buffer_shim();
      init_utils();
      HashMD = class extends Hash {
        constructor(blockLen, outputLen, padOffset, isLE) {
          super();
          this.finished = false;
          this.length = 0;
          this.pos = 0;
          this.destroyed = false;
          this.blockLen = blockLen;
          this.outputLen = outputLen;
          this.padOffset = padOffset;
          this.isLE = isLE;
          this.buffer = new Uint8Array(blockLen);
          this.view = createView(this.buffer);
        }
        update(data) {
          aexists(this);
          data = toBytes(data);
          abytes(data);
          const { view, buffer: buffer2, blockLen } = this;
          const len = data.length;
          for (let pos = 0; pos < len; ) {
            const take = Math.min(blockLen - this.pos, len - pos);
            if (take === blockLen) {
              const dataView2 = createView(data);
              for (; blockLen <= len - pos; pos += blockLen)
                this.process(dataView2, pos);
              continue;
            }
            buffer2.set(data.subarray(pos, pos + take), this.pos);
            this.pos += take;
            pos += take;
            if (this.pos === blockLen) {
              this.process(view, 0);
              this.pos = 0;
            }
          }
          this.length += data.length;
          this.roundClean();
          return this;
        }
        digestInto(out) {
          aexists(this);
          aoutput(out, this);
          this.finished = true;
          const { buffer: buffer2, view, blockLen, isLE } = this;
          let { pos } = this;
          buffer2[pos++] = 128;
          clean(this.buffer.subarray(pos));
          if (this.padOffset > blockLen - pos) {
            this.process(view, 0);
            pos = 0;
          }
          for (let i = pos; i < blockLen; i++)
            buffer2[i] = 0;
          setBigUint64(view, blockLen - 8, BigInt(this.length * 8), isLE);
          this.process(view, 0);
          const oview = createView(out);
          const len = this.outputLen;
          if (len % 4)
            throw new Error("_sha2: outputLen should be aligned to 32bit");
          const outLen = len / 4;
          const state = this.get();
          if (outLen > state.length)
            throw new Error("_sha2: outputLen bigger than state");
          for (let i = 0; i < outLen; i++)
            oview.setUint32(4 * i, state[i], isLE);
        }
        digest() {
          const { buffer: buffer2, outputLen } = this;
          this.digestInto(buffer2);
          const res = buffer2.slice(0, outputLen);
          this.destroy();
          return res;
        }
        _cloneInto(to) {
          to || (to = new this.constructor());
          to.set(...this.get());
          const { blockLen, buffer: buffer2, length, finished, destroyed, pos } = this;
          to.destroyed = destroyed;
          to.finished = finished;
          to.length = length;
          to.pos = pos;
          if (length % blockLen)
            to.buffer.set(buffer2);
          return to;
        }
        clone() {
          return this._cloneInto();
        }
      };
      SHA256_IV = /* @__PURE__ */ Uint32Array.from([
        1779033703,
        3144134277,
        1013904242,
        2773480762,
        1359893119,
        2600822924,
        528734635,
        1541459225
      ]);
    }
  });

  // node_modules/@noble/hashes/esm/sha2.js
  var SHA256_K, SHA256_W, SHA256, sha256;
  var init_sha2 = __esm({
    "node_modules/@noble/hashes/esm/sha2.js"() {
      init_buffer_shim();
      init_md();
      init_utils();
      SHA256_K = /* @__PURE__ */ Uint32Array.from([
        1116352408,
        1899447441,
        3049323471,
        3921009573,
        961987163,
        1508970993,
        2453635748,
        2870763221,
        3624381080,
        310598401,
        607225278,
        1426881987,
        1925078388,
        2162078206,
        2614888103,
        3248222580,
        3835390401,
        4022224774,
        264347078,
        604807628,
        770255983,
        1249150122,
        1555081692,
        1996064986,
        2554220882,
        2821834349,
        2952996808,
        3210313671,
        3336571891,
        3584528711,
        113926993,
        338241895,
        666307205,
        773529912,
        1294757372,
        1396182291,
        1695183700,
        1986661051,
        2177026350,
        2456956037,
        2730485921,
        2820302411,
        3259730800,
        3345764771,
        3516065817,
        3600352804,
        4094571909,
        275423344,
        430227734,
        506948616,
        659060556,
        883997877,
        958139571,
        1322822218,
        1537002063,
        1747873779,
        1955562222,
        2024104815,
        2227730452,
        2361852424,
        2428436474,
        2756734187,
        3204031479,
        3329325298
      ]);
      SHA256_W = /* @__PURE__ */ new Uint32Array(64);
      SHA256 = class extends HashMD {
        constructor(outputLen = 32) {
          super(64, outputLen, 8, false);
          this.A = SHA256_IV[0] | 0;
          this.B = SHA256_IV[1] | 0;
          this.C = SHA256_IV[2] | 0;
          this.D = SHA256_IV[3] | 0;
          this.E = SHA256_IV[4] | 0;
          this.F = SHA256_IV[5] | 0;
          this.G = SHA256_IV[6] | 0;
          this.H = SHA256_IV[7] | 0;
        }
        get() {
          const { A, B, C, D, E, F, G, H } = this;
          return [A, B, C, D, E, F, G, H];
        }
        // prettier-ignore
        set(A, B, C, D, E, F, G, H) {
          this.A = A | 0;
          this.B = B | 0;
          this.C = C | 0;
          this.D = D | 0;
          this.E = E | 0;
          this.F = F | 0;
          this.G = G | 0;
          this.H = H | 0;
        }
        process(view, offset) {
          for (let i = 0; i < 16; i++, offset += 4)
            SHA256_W[i] = view.getUint32(offset, false);
          for (let i = 16; i < 64; i++) {
            const W15 = SHA256_W[i - 15];
            const W2 = SHA256_W[i - 2];
            const s0 = rotr(W15, 7) ^ rotr(W15, 18) ^ W15 >>> 3;
            const s1 = rotr(W2, 17) ^ rotr(W2, 19) ^ W2 >>> 10;
            SHA256_W[i] = s1 + SHA256_W[i - 7] + s0 + SHA256_W[i - 16] | 0;
          }
          let { A, B, C, D, E, F, G, H } = this;
          for (let i = 0; i < 64; i++) {
            const sigma1 = rotr(E, 6) ^ rotr(E, 11) ^ rotr(E, 25);
            const T1 = H + sigma1 + Chi(E, F, G) + SHA256_K[i] + SHA256_W[i] | 0;
            const sigma0 = rotr(A, 2) ^ rotr(A, 13) ^ rotr(A, 22);
            const T2 = sigma0 + Maj(A, B, C) | 0;
            H = G;
            G = F;
            F = E;
            E = D + T1 | 0;
            D = C;
            C = B;
            B = A;
            A = T1 + T2 | 0;
          }
          A = A + this.A | 0;
          B = B + this.B | 0;
          C = C + this.C | 0;
          D = D + this.D | 0;
          E = E + this.E | 0;
          F = F + this.F | 0;
          G = G + this.G | 0;
          H = H + this.H | 0;
          this.set(A, B, C, D, E, F, G, H);
        }
        roundClean() {
          clean(SHA256_W);
        }
        destroy() {
          this.set(0, 0, 0, 0, 0, 0, 0, 0);
          clean(this.buffer);
        }
      };
      sha256 = /* @__PURE__ */ createHasher(() => new SHA256());
    }
  });

  // node_modules/@noble/hashes/esm/sha256.js
  var sha2562;
  var init_sha256 = __esm({
    "node_modules/@noble/hashes/esm/sha256.js"() {
      init_buffer_shim();
      init_sha2();
      sha2562 = sha256;
    }
  });

  // node_modules/@gandlaf21/bc-ur/dist/lib/es6/utils.js
  var import_buffer2, CRC_TABLE, crc32, sha256Hash, partition, split, getCRC, getCRCHex, toUint32, intToBytes, isURType, arraysEqual, arrayContains, setDifference, bufferXOR;
  var init_utils2 = __esm({
    "node_modules/@gandlaf21/bc-ur/dist/lib/es6/utils.js"() {
      init_buffer_shim();
      import_buffer2 = __toESM(require_buffer());
      init_sha256();
      CRC_TABLE = (function() {
        var c;
        var crcTable = [];
        for (var n = 0; n < 256; n++) {
          c = n;
          for (var k = 0; k < 8; k++) {
            c = c & 1 ? 3988292384 ^ c >>> 1 : c >>> 1;
          }
          crcTable[n] = c;
        }
        return crcTable;
      })();
      crc32 = function(message) {
        var crc = 0 ^ -1;
        for (var i = 0; i < message.length; i++) {
          crc = crc >>> 8 ^ CRC_TABLE[(crc ^ message[i]) & 255];
        }
        return (crc ^ -1) >>> 0;
      };
      sha256Hash = function(data) {
        return import_buffer2.Buffer.from(sha2562(data));
      };
      partition = function(s, n) {
        return s.match(new RegExp(".{1," + n + "}", "g")) || [s];
      };
      split = function(s, length) {
        return [s.slice(0, -length), s.slice(-length)];
      };
      getCRC = function(message) {
        return crc32(message);
      };
      getCRCHex = function(message) {
        return crc32(message).toString(16).padStart(8, "0");
      };
      toUint32 = function(number) {
        return number >>> 0;
      };
      intToBytes = function(num) {
        var arr = new ArrayBuffer(4);
        var view = new DataView(arr);
        view.setUint32(0, num, false);
        return import_buffer2.Buffer.from(arr);
      };
      isURType = function(type) {
        return type.split("").every(function(_, index) {
          var c = type.charCodeAt(index);
          if ("a".charCodeAt(0) <= c && c <= "z".charCodeAt(0))
            return true;
          if ("0".charCodeAt(0) <= c && c <= "9".charCodeAt(0))
            return true;
          if (c === "-".charCodeAt(0))
            return true;
          return false;
        });
      };
      arraysEqual = function(ar1, ar2) {
        if (ar1.length !== ar2.length) {
          return false;
        }
        return ar1.every(function(el) {
          return ar2.includes(el);
        });
      };
      arrayContains = function(ar1, ar2) {
        return ar2.every(function(v) {
          return ar1.includes(v);
        });
      };
      setDifference = function(ar1, ar2) {
        return ar1.filter(function(x) {
          return ar2.indexOf(x) < 0;
        });
      };
      bufferXOR = function(a, b) {
        var length = Math.max(a.length, b.length);
        var buffer2 = import_buffer2.Buffer.allocUnsafe(length);
        for (var i = 0; i < length; ++i) {
          buffer2[i] = a[i] ^ b[i];
        }
        return buffer2;
      };
    }
  });

  // node_modules/cborg/lib/is.js
  function is(value) {
    if (value === null) {
      return "null";
    }
    if (value === void 0) {
      return "undefined";
    }
    if (value === true || value === false) {
      return "boolean";
    }
    const typeOf = typeof value;
    if (typeOf === "string" || typeOf === "number" || typeOf === "bigint" || typeOf === "symbol") {
      return typeOf;
    }
    if (typeOf === "function") {
      return "Function";
    }
    if (Array.isArray(value)) {
      return "Array";
    }
    if (value instanceof Uint8Array) {
      return "Uint8Array";
    }
    if (value.constructor === Object) {
      return "Object";
    }
    const objectType = getObjectType(value);
    if (objectType) {
      return objectType;
    }
    return "Object";
  }
  function getObjectType(value) {
    const objectTypeName = Object.prototype.toString.call(value).slice(8, -1);
    if (objectTypeNames.includes(objectTypeName)) {
      return objectTypeName;
    }
    return void 0;
  }
  var objectTypeNames;
  var init_is = __esm({
    "node_modules/cborg/lib/is.js"() {
      init_buffer_shim();
      objectTypeNames = [
        "Object",
        // for Object.create(null) and other non-plain objects
        "RegExp",
        "Date",
        "Error",
        "Map",
        "Set",
        "WeakMap",
        "WeakSet",
        "ArrayBuffer",
        "SharedArrayBuffer",
        "DataView",
        "Promise",
        "URL",
        "HTMLElement",
        "Int8Array",
        "Uint8ClampedArray",
        "Int16Array",
        "Uint16Array",
        "Int32Array",
        "Uint32Array",
        "Float32Array",
        "Float64Array",
        "BigInt64Array",
        "BigUint64Array"
      ];
    }
  });

  // node_modules/cborg/lib/token.js
  var Type, Token;
  var init_token = __esm({
    "node_modules/cborg/lib/token.js"() {
      init_buffer_shim();
      Type = class {
        /**
         * @param {number} major
         * @param {string} name
         * @param {boolean} terminal
         */
        constructor(major, name, terminal) {
          this.major = major;
          this.majorEncoded = major << 5;
          this.name = name;
          this.terminal = terminal;
        }
        /* c8 ignore next 3 */
        toString() {
          return `Type[${this.major}].${this.name}`;
        }
        /**
         * @param {Type} typ
         * @returns {number}
         */
        compare(typ) {
          return this.major < typ.major ? -1 : this.major > typ.major ? 1 : 0;
        }
        /**
         * Check equality between two Type instances. Safe to use across different
         * copies of the Type class (e.g., when bundlers duplicate the module).
         * (major, name) uniquely identifies a Type; terminal is implied by these.
         * @param {Type} a
         * @param {Type} b
         * @returns {boolean}
         */
        static equals(a, b) {
          return a === b || a.major === b.major && a.name === b.name;
        }
      };
      Type.uint = new Type(0, "uint", true);
      Type.negint = new Type(1, "negint", true);
      Type.bytes = new Type(2, "bytes", true);
      Type.string = new Type(3, "string", true);
      Type.array = new Type(4, "array", false);
      Type.map = new Type(5, "map", false);
      Type.tag = new Type(6, "tag", false);
      Type.float = new Type(7, "float", true);
      Type.false = new Type(7, "false", true);
      Type.true = new Type(7, "true", true);
      Type.null = new Type(7, "null", true);
      Type.undefined = new Type(7, "undefined", true);
      Type.break = new Type(7, "break", true);
      Token = class {
        /**
         * @param {Type} type
         * @param {any} [value]
         * @param {number} [encodedLength]
         */
        constructor(type, value, encodedLength) {
          this.type = type;
          this.value = value;
          this.encodedLength = encodedLength;
          this.encodedBytes = void 0;
          this.byteValue = void 0;
        }
        /* c8 ignore next 3 */
        toString() {
          return `Token[${this.type}].${this.value}`;
        }
      };
    }
  });

  // node_modules/cborg/lib/byte-utils.js
  function isBuffer(buf) {
    return useBuffer && globalThis.Buffer.isBuffer(buf);
  }
  function asU8A(buf) {
    if (!(buf instanceof Uint8Array)) {
      return Uint8Array.from(buf);
    }
    return isBuffer(buf) ? new Uint8Array(buf.buffer, buf.byteOffset, buf.byteLength) : buf;
  }
  function compare(b1, b2) {
    if (isBuffer(b1) && isBuffer(b2)) {
      return b1.compare(b2);
    }
    for (let i = 0; i < b1.length; i++) {
      if (b1[i] === b2[i]) {
        continue;
      }
      return b1[i] < b2[i] ? -1 : 1;
    }
    return 0;
  }
  function utf8ToBytes2(str) {
    const out = [];
    let p = 0;
    for (let i = 0; i < str.length; i++) {
      let c = str.charCodeAt(i);
      if (c < 128) {
        out[p++] = c;
      } else if (c < 2048) {
        out[p++] = c >> 6 | 192;
        out[p++] = c & 63 | 128;
      } else if ((c & 64512) === 55296 && i + 1 < str.length && (str.charCodeAt(i + 1) & 64512) === 56320) {
        c = 65536 + ((c & 1023) << 10) + (str.charCodeAt(++i) & 1023);
        out[p++] = c >> 18 | 240;
        out[p++] = c >> 12 & 63 | 128;
        out[p++] = c >> 6 & 63 | 128;
        out[p++] = c & 63 | 128;
      } else {
        if (c >= 55296 && c <= 57343) {
          c = 65533;
        }
        out[p++] = c >> 12 | 224;
        out[p++] = c >> 6 & 63 | 128;
        out[p++] = c & 63 | 128;
      }
    }
    return out;
  }
  var useBuffer, textEncoder, FROM_STRING_THRESHOLD_BUFFER, FROM_STRING_THRESHOLD_TEXTENCODER, fromString, fromArray, slice, concat, alloc;
  var init_byte_utils = __esm({
    "node_modules/cborg/lib/byte-utils.js"() {
      init_buffer_shim();
      useBuffer = globalThis.process && // @ts-ignore
      !globalThis.process.browser && // @ts-ignore
      globalThis.Buffer && // @ts-ignore
      typeof globalThis.Buffer.isBuffer === "function";
      textEncoder = new TextEncoder();
      FROM_STRING_THRESHOLD_BUFFER = 24;
      FROM_STRING_THRESHOLD_TEXTENCODER = 200;
      fromString = useBuffer ? (
        // eslint-disable-line operator-linebreak
        /**
         * @param {string} string
         */
        (string) => {
          return string.length >= FROM_STRING_THRESHOLD_BUFFER ? (
            // eslint-disable-line operator-linebreak
            // @ts-ignore
            globalThis.Buffer.from(string)
          ) : utf8ToBytes2(string);
        }
      ) : (
        // eslint-disable-line operator-linebreak
        /**
         * @param {string} string
         */
        (string) => {
          return string.length >= FROM_STRING_THRESHOLD_TEXTENCODER ? textEncoder.encode(string) : utf8ToBytes2(string);
        }
      );
      fromArray = (arr) => {
        return Uint8Array.from(arr);
      };
      slice = useBuffer ? (
        // eslint-disable-line operator-linebreak
        /**
         * @param {Uint8Array} bytes
         * @param {number} start
         * @param {number} end
         */
        // Buffer.slice() returns a view, not a copy, so we need special handling
        (bytes, start, end) => {
          if (isBuffer(bytes)) {
            return new Uint8Array(bytes.subarray(start, end));
          }
          return bytes.slice(start, end);
        }
      ) : (
        // eslint-disable-line operator-linebreak
        /**
         * @param {Uint8Array} bytes
         * @param {number} start
         * @param {number} end
         */
        (bytes, start, end) => {
          return bytes.slice(start, end);
        }
      );
      concat = useBuffer ? (
        // eslint-disable-line operator-linebreak
        /**
         * @param {Uint8Array[]} chunks
         * @param {number} length
         * @returns {Uint8Array}
         */
        (chunks, length) => {
          chunks = chunks.map((c) => c instanceof Uint8Array ? c : (
            // eslint-disable-line operator-linebreak
            // @ts-ignore
            globalThis.Buffer.from(c)
          ));
          return asU8A(globalThis.Buffer.concat(chunks, length));
        }
      ) : (
        // eslint-disable-line operator-linebreak
        /**
         * @param {Uint8Array[]} chunks
         * @param {number} length
         * @returns {Uint8Array}
         */
        (chunks, length) => {
          const out = new Uint8Array(length);
          let off = 0;
          for (let b of chunks) {
            if (off + b.length > out.length) {
              b = b.subarray(0, out.length - off);
            }
            out.set(b, off);
            off += b.length;
          }
          return out;
        }
      );
      alloc = useBuffer ? (
        // eslint-disable-line operator-linebreak
        /**
         * @param {number} size
         * @returns {Uint8Array}
         */
        (size) => {
          return globalThis.Buffer.allocUnsafe(size);
        }
      ) : (
        // eslint-disable-line operator-linebreak
        /**
         * @param {number} size
         * @returns {Uint8Array}
         */
        (size) => {
          return new Uint8Array(size);
        }
      );
    }
  });

  // node_modules/cborg/lib/bl.js
  var defaultChunkSize, Bl, U8Bl;
  var init_bl = __esm({
    "node_modules/cborg/lib/bl.js"() {
      init_buffer_shim();
      init_byte_utils();
      defaultChunkSize = 256;
      Bl = class {
        /**
         * @param {number} [chunkSize]
         */
        constructor(chunkSize = defaultChunkSize) {
          this.chunkSize = chunkSize;
          this.cursor = 0;
          this.maxCursor = -1;
          this.chunks = [];
          this._initReuseChunk = null;
        }
        reset() {
          this.cursor = 0;
          this.maxCursor = -1;
          if (this.chunks.length) {
            this.chunks = [];
          }
          if (this._initReuseChunk !== null) {
            this.chunks.push(this._initReuseChunk);
            this.maxCursor = this._initReuseChunk.length - 1;
          }
        }
        /**
         * @param {Uint8Array|number[]} bytes
         */
        push(bytes) {
          let topChunk = this.chunks[this.chunks.length - 1];
          const newMax = this.cursor + bytes.length;
          if (newMax <= this.maxCursor + 1) {
            const chunkPos = topChunk.length - (this.maxCursor - this.cursor) - 1;
            topChunk.set(bytes, chunkPos);
          } else {
            if (topChunk) {
              const chunkPos = topChunk.length - (this.maxCursor - this.cursor) - 1;
              if (chunkPos < topChunk.length) {
                this.chunks[this.chunks.length - 1] = topChunk.subarray(0, chunkPos);
                this.maxCursor = this.cursor - 1;
              }
            }
            if (bytes.length < 64 && bytes.length < this.chunkSize) {
              topChunk = alloc(this.chunkSize);
              this.chunks.push(topChunk);
              this.maxCursor += topChunk.length;
              if (this._initReuseChunk === null) {
                this._initReuseChunk = topChunk;
              }
              topChunk.set(bytes, 0);
            } else {
              this.chunks.push(bytes);
              this.maxCursor += bytes.length;
            }
          }
          this.cursor += bytes.length;
        }
        /**
         * @param {boolean} [reset]
         * @returns {Uint8Array}
         */
        toBytes(reset = false) {
          let byts;
          if (this.chunks.length === 1) {
            const chunk = this.chunks[0];
            if (reset && this.cursor > chunk.length / 2) {
              byts = this.cursor === chunk.length ? chunk : chunk.subarray(0, this.cursor);
              this._initReuseChunk = null;
              this.chunks = [];
            } else {
              byts = slice(chunk, 0, this.cursor);
            }
          } else {
            byts = concat(this.chunks, this.cursor);
          }
          if (reset) {
            this.reset();
          }
          return byts;
        }
      };
      U8Bl = class {
        /**
         * @param {Uint8Array} dest
         */
        constructor(dest) {
          this.dest = dest;
          this.cursor = 0;
          this.chunks = [dest];
        }
        reset() {
          this.cursor = 0;
        }
        /**
         * @param {Uint8Array|number[]} bytes
         */
        push(bytes) {
          if (this.cursor + bytes.length > this.dest.length) {
            throw new Error("write out of bounds, destination buffer is too small");
          }
          this.dest.set(bytes, this.cursor);
          this.cursor += bytes.length;
        }
        /**
         * @param {boolean} [reset]
         * @returns {Uint8Array}
         */
        toBytes(reset = false) {
          const byts = this.dest.subarray(0, this.cursor);
          if (reset) {
            this.reset();
          }
          return byts;
        }
      };
    }
  });

  // node_modules/cborg/lib/common.js
  function assertEnoughData(data, pos, need) {
    if (data.length - pos < need) {
      throw new Error(`${decodeErrPrefix} not enough data for type`);
    }
  }
  var decodeErrPrefix, encodeErrPrefix, uintMinorPrefixBytes;
  var init_common = __esm({
    "node_modules/cborg/lib/common.js"() {
      init_buffer_shim();
      decodeErrPrefix = "CBOR decode error:";
      encodeErrPrefix = "CBOR encode error:";
      uintMinorPrefixBytes = [];
      uintMinorPrefixBytes[23] = 1;
      uintMinorPrefixBytes[24] = 2;
      uintMinorPrefixBytes[25] = 3;
      uintMinorPrefixBytes[26] = 5;
      uintMinorPrefixBytes[27] = 9;
    }
  });

  // node_modules/cborg/lib/0uint.js
  function readUint8(data, offset, options) {
    assertEnoughData(data, offset, 1);
    const value = data[offset];
    if (options.strict === true && value < uintBoundaries[0]) {
      throw new Error(`${decodeErrPrefix} integer encoded in more bytes than necessary (strict decode)`);
    }
    return value;
  }
  function readUint16(data, offset, options) {
    assertEnoughData(data, offset, 2);
    const value = data[offset] << 8 | data[offset + 1];
    if (options.strict === true && value < uintBoundaries[1]) {
      throw new Error(`${decodeErrPrefix} integer encoded in more bytes than necessary (strict decode)`);
    }
    return value;
  }
  function readUint32(data, offset, options) {
    assertEnoughData(data, offset, 4);
    const value = data[offset] * 16777216 + (data[offset + 1] << 16) + (data[offset + 2] << 8) + data[offset + 3];
    if (options.strict === true && value < uintBoundaries[2]) {
      throw new Error(`${decodeErrPrefix} integer encoded in more bytes than necessary (strict decode)`);
    }
    return value;
  }
  function readUint64(data, offset, options) {
    assertEnoughData(data, offset, 8);
    const hi = data[offset] * 16777216 + (data[offset + 1] << 16) + (data[offset + 2] << 8) + data[offset + 3];
    const lo = data[offset + 4] * 16777216 + (data[offset + 5] << 16) + (data[offset + 6] << 8) + data[offset + 7];
    const value = (BigInt(hi) << BigInt(32)) + BigInt(lo);
    if (options.strict === true && value < uintBoundaries[3]) {
      throw new Error(`${decodeErrPrefix} integer encoded in more bytes than necessary (strict decode)`);
    }
    if (value <= Number.MAX_SAFE_INTEGER) {
      return Number(value);
    }
    if (options.allowBigInt === true) {
      return value;
    }
    throw new Error(`${decodeErrPrefix} integers outside of the safe integer range are not supported`);
  }
  function decodeUint8(data, pos, _minor, options) {
    return new Token(Type.uint, readUint8(data, pos + 1, options), 2);
  }
  function decodeUint16(data, pos, _minor, options) {
    return new Token(Type.uint, readUint16(data, pos + 1, options), 3);
  }
  function decodeUint32(data, pos, _minor, options) {
    return new Token(Type.uint, readUint32(data, pos + 1, options), 5);
  }
  function decodeUint64(data, pos, _minor, options) {
    return new Token(Type.uint, readUint64(data, pos + 1, options), 9);
  }
  function encodeUint(writer, token) {
    return encodeUintValue(writer, 0, token.value);
  }
  function encodeUintValue(writer, major, uint) {
    if (uint < uintBoundaries[0]) {
      const nuint = Number(uint);
      writer.push([major | nuint]);
    } else if (uint < uintBoundaries[1]) {
      const nuint = Number(uint);
      writer.push([major | 24, nuint]);
    } else if (uint < uintBoundaries[2]) {
      const nuint = Number(uint);
      writer.push([major | 25, nuint >>> 8, nuint & 255]);
    } else if (uint < uintBoundaries[3]) {
      const nuint = Number(uint);
      writer.push([major | 26, nuint >>> 24 & 255, nuint >>> 16 & 255, nuint >>> 8 & 255, nuint & 255]);
    } else {
      const buint = BigInt(uint);
      if (buint < uintBoundaries[4]) {
        const set = [major | 27, 0, 0, 0, 0, 0, 0, 0];
        let lo = Number(buint & BigInt(4294967295));
        let hi = Number(buint >> BigInt(32) & BigInt(4294967295));
        set[8] = lo & 255;
        lo = lo >> 8;
        set[7] = lo & 255;
        lo = lo >> 8;
        set[6] = lo & 255;
        lo = lo >> 8;
        set[5] = lo & 255;
        set[4] = hi & 255;
        hi = hi >> 8;
        set[3] = hi & 255;
        hi = hi >> 8;
        set[2] = hi & 255;
        hi = hi >> 8;
        set[1] = hi & 255;
        writer.push(set);
      } else {
        throw new Error(`${decodeErrPrefix} encountered BigInt larger than allowable range`);
      }
    }
  }
  var uintBoundaries;
  var init_uint = __esm({
    "node_modules/cborg/lib/0uint.js"() {
      init_buffer_shim();
      init_token();
      init_common();
      uintBoundaries = [24, 256, 65536, 4294967296, BigInt("18446744073709551616")];
      encodeUint.encodedSize = function encodedSize(token) {
        return encodeUintValue.encodedSize(token.value);
      };
      encodeUintValue.encodedSize = function encodedSize2(uint) {
        if (uint < uintBoundaries[0]) {
          return 1;
        }
        if (uint < uintBoundaries[1]) {
          return 2;
        }
        if (uint < uintBoundaries[2]) {
          return 3;
        }
        if (uint < uintBoundaries[3]) {
          return 5;
        }
        return 9;
      };
      encodeUint.compareTokens = function compareTokens(tok1, tok2) {
        return tok1.value < tok2.value ? -1 : tok1.value > tok2.value ? 1 : (
          /* c8 ignore next */
          0
        );
      };
    }
  });

  // node_modules/cborg/lib/1negint.js
  function decodeNegint8(data, pos, _minor, options) {
    return new Token(Type.negint, -1 - readUint8(data, pos + 1, options), 2);
  }
  function decodeNegint16(data, pos, _minor, options) {
    return new Token(Type.negint, -1 - readUint16(data, pos + 1, options), 3);
  }
  function decodeNegint32(data, pos, _minor, options) {
    return new Token(Type.negint, -1 - readUint32(data, pos + 1, options), 5);
  }
  function decodeNegint64(data, pos, _minor, options) {
    const int = readUint64(data, pos + 1, options);
    if (typeof int !== "bigint") {
      const value = -1 - int;
      if (value >= Number.MIN_SAFE_INTEGER) {
        return new Token(Type.negint, value, 9);
      }
    }
    if (options.allowBigInt !== true) {
      throw new Error(`${decodeErrPrefix} integers outside of the safe integer range are not supported`);
    }
    return new Token(Type.negint, neg1b - BigInt(int), 9);
  }
  function encodeNegint(writer, token) {
    const negint = token.value;
    const unsigned = typeof negint === "bigint" ? negint * neg1b - pos1b : negint * -1 - 1;
    encodeUintValue(writer, token.type.majorEncoded, unsigned);
  }
  var neg1b, pos1b;
  var init_negint = __esm({
    "node_modules/cborg/lib/1negint.js"() {
      init_buffer_shim();
      init_token();
      init_uint();
      init_common();
      neg1b = BigInt(-1);
      pos1b = BigInt(1);
      encodeNegint.encodedSize = function encodedSize3(token) {
        const negint = token.value;
        const unsigned = typeof negint === "bigint" ? negint * neg1b - pos1b : negint * -1 - 1;
        if (unsigned < uintBoundaries[0]) {
          return 1;
        }
        if (unsigned < uintBoundaries[1]) {
          return 2;
        }
        if (unsigned < uintBoundaries[2]) {
          return 3;
        }
        if (unsigned < uintBoundaries[3]) {
          return 5;
        }
        return 9;
      };
      encodeNegint.compareTokens = function compareTokens2(tok1, tok2) {
        return tok1.value < tok2.value ? 1 : tok1.value > tok2.value ? -1 : (
          /* c8 ignore next */
          0
        );
      };
    }
  });

  // node_modules/cborg/lib/2bytes.js
  function toToken(data, pos, prefix, length) {
    assertEnoughData(data, pos, prefix + length);
    const buf = data.slice(pos + prefix, pos + prefix + length);
    return new Token(Type.bytes, buf, prefix + length);
  }
  function decodeBytesCompact(data, pos, minor, _options) {
    return toToken(data, pos, 1, minor);
  }
  function decodeBytes8(data, pos, _minor, options) {
    return toToken(data, pos, 2, readUint8(data, pos + 1, options));
  }
  function decodeBytes16(data, pos, _minor, options) {
    return toToken(data, pos, 3, readUint16(data, pos + 1, options));
  }
  function decodeBytes32(data, pos, _minor, options) {
    return toToken(data, pos, 5, readUint32(data, pos + 1, options));
  }
  function decodeBytes64(data, pos, _minor, options) {
    const l = readUint64(data, pos + 1, options);
    if (typeof l === "bigint") {
      throw new Error(`${decodeErrPrefix} 64-bit integer bytes lengths not supported`);
    }
    return toToken(data, pos, 9, l);
  }
  function tokenBytes(token) {
    if (token.encodedBytes === void 0) {
      token.encodedBytes = Type.equals(token.type, Type.string) ? fromString(token.value) : token.value;
    }
    return token.encodedBytes;
  }
  function encodeBytes(writer, token) {
    const bytes = tokenBytes(token);
    encodeUintValue(writer, token.type.majorEncoded, bytes.length);
    writer.push(bytes);
  }
  function compareBytes(b1, b2) {
    return b1.length < b2.length ? -1 : b1.length > b2.length ? 1 : compare(b1, b2);
  }
  var init_bytes = __esm({
    "node_modules/cborg/lib/2bytes.js"() {
      init_buffer_shim();
      init_token();
      init_common();
      init_uint();
      init_byte_utils();
      encodeBytes.encodedSize = function encodedSize4(token) {
        const bytes = tokenBytes(token);
        return encodeUintValue.encodedSize(bytes.length) + bytes.length;
      };
      encodeBytes.compareTokens = function compareTokens3(tok1, tok2) {
        return compareBytes(tokenBytes(tok1), tokenBytes(tok2));
      };
    }
  });

  // node_modules/cborg/lib/3string.js
  function toStr(bytes, start, end) {
    const len = end - start;
    if (len < ASCII_THRESHOLD) {
      let str = "";
      for (let i = start; i < end; i++) {
        const c = bytes[i];
        if (c & 128) {
          return textDecoder.decode(bytes.subarray(start, end));
        }
        str += String.fromCharCode(c);
      }
      return str;
    }
    return textDecoder.decode(bytes.subarray(start, end));
  }
  function toToken2(data, pos, prefix, length, options) {
    const totLength = prefix + length;
    assertEnoughData(data, pos, totLength);
    const tok = new Token(Type.string, toStr(data, pos + prefix, pos + totLength), totLength);
    if (options.retainStringBytes === true) {
      tok.byteValue = data.slice(pos + prefix, pos + totLength);
    }
    return tok;
  }
  function decodeStringCompact(data, pos, minor, options) {
    return toToken2(data, pos, 1, minor, options);
  }
  function decodeString8(data, pos, _minor, options) {
    return toToken2(data, pos, 2, readUint8(data, pos + 1, options), options);
  }
  function decodeString16(data, pos, _minor, options) {
    return toToken2(data, pos, 3, readUint16(data, pos + 1, options), options);
  }
  function decodeString32(data, pos, _minor, options) {
    return toToken2(data, pos, 5, readUint32(data, pos + 1, options), options);
  }
  function decodeString64(data, pos, _minor, options) {
    const l = readUint64(data, pos + 1, options);
    if (typeof l === "bigint") {
      throw new Error(`${decodeErrPrefix} 64-bit integer string lengths not supported`);
    }
    return toToken2(data, pos, 9, l, options);
  }
  var textDecoder, ASCII_THRESHOLD, encodeString;
  var init_string = __esm({
    "node_modules/cborg/lib/3string.js"() {
      init_buffer_shim();
      init_token();
      init_common();
      init_uint();
      init_bytes();
      textDecoder = new TextDecoder();
      ASCII_THRESHOLD = 32;
      encodeString = encodeBytes;
    }
  });

  // node_modules/cborg/lib/4array.js
  function toToken3(_data, _pos, prefix, length) {
    return new Token(Type.array, length, prefix);
  }
  function decodeArrayCompact(data, pos, minor, _options) {
    return toToken3(data, pos, 1, minor);
  }
  function decodeArray8(data, pos, _minor, options) {
    return toToken3(data, pos, 2, readUint8(data, pos + 1, options));
  }
  function decodeArray16(data, pos, _minor, options) {
    return toToken3(data, pos, 3, readUint16(data, pos + 1, options));
  }
  function decodeArray32(data, pos, _minor, options) {
    return toToken3(data, pos, 5, readUint32(data, pos + 1, options));
  }
  function decodeArray64(data, pos, _minor, options) {
    const l = readUint64(data, pos + 1, options);
    if (typeof l === "bigint") {
      throw new Error(`${decodeErrPrefix} 64-bit integer array lengths not supported`);
    }
    return toToken3(data, pos, 9, l);
  }
  function decodeArrayIndefinite(data, pos, _minor, options) {
    if (options.allowIndefinite === false) {
      throw new Error(`${decodeErrPrefix} indefinite length items not allowed`);
    }
    return toToken3(data, pos, 1, Infinity);
  }
  function encodeArray(writer, token) {
    encodeUintValue(writer, Type.array.majorEncoded, token.value);
  }
  var init_array = __esm({
    "node_modules/cborg/lib/4array.js"() {
      init_buffer_shim();
      init_token();
      init_uint();
      init_common();
      encodeArray.compareTokens = encodeUint.compareTokens;
      encodeArray.encodedSize = function encodedSize5(token) {
        return encodeUintValue.encodedSize(token.value);
      };
    }
  });

  // node_modules/cborg/lib/5map.js
  function toToken4(_data, _pos, prefix, length) {
    return new Token(Type.map, length, prefix);
  }
  function decodeMapCompact(data, pos, minor, _options) {
    return toToken4(data, pos, 1, minor);
  }
  function decodeMap8(data, pos, _minor, options) {
    return toToken4(data, pos, 2, readUint8(data, pos + 1, options));
  }
  function decodeMap16(data, pos, _minor, options) {
    return toToken4(data, pos, 3, readUint16(data, pos + 1, options));
  }
  function decodeMap32(data, pos, _minor, options) {
    return toToken4(data, pos, 5, readUint32(data, pos + 1, options));
  }
  function decodeMap64(data, pos, _minor, options) {
    const l = readUint64(data, pos + 1, options);
    if (typeof l === "bigint") {
      throw new Error(`${decodeErrPrefix} 64-bit integer map lengths not supported`);
    }
    return toToken4(data, pos, 9, l);
  }
  function decodeMapIndefinite(data, pos, _minor, options) {
    if (options.allowIndefinite === false) {
      throw new Error(`${decodeErrPrefix} indefinite length items not allowed`);
    }
    return toToken4(data, pos, 1, Infinity);
  }
  function encodeMap(writer, token) {
    encodeUintValue(writer, Type.map.majorEncoded, token.value);
  }
  var init_map = __esm({
    "node_modules/cborg/lib/5map.js"() {
      init_buffer_shim();
      init_token();
      init_uint();
      init_common();
      encodeMap.compareTokens = encodeUint.compareTokens;
      encodeMap.encodedSize = function encodedSize6(token) {
        return encodeUintValue.encodedSize(token.value);
      };
    }
  });

  // node_modules/cborg/lib/6tag.js
  function decodeTagCompact(_data, _pos, minor, _options) {
    return new Token(Type.tag, minor, 1);
  }
  function decodeTag8(data, pos, _minor, options) {
    return new Token(Type.tag, readUint8(data, pos + 1, options), 2);
  }
  function decodeTag16(data, pos, _minor, options) {
    return new Token(Type.tag, readUint16(data, pos + 1, options), 3);
  }
  function decodeTag32(data, pos, _minor, options) {
    return new Token(Type.tag, readUint32(data, pos + 1, options), 5);
  }
  function decodeTag64(data, pos, _minor, options) {
    return new Token(Type.tag, readUint64(data, pos + 1, options), 9);
  }
  function encodeTag(writer, token) {
    encodeUintValue(writer, Type.tag.majorEncoded, token.value);
  }
  var init_tag = __esm({
    "node_modules/cborg/lib/6tag.js"() {
      init_buffer_shim();
      init_token();
      init_uint();
      encodeTag.compareTokens = encodeUint.compareTokens;
      encodeTag.encodedSize = function encodedSize7(token) {
        return encodeUintValue.encodedSize(token.value);
      };
    }
  });

  // node_modules/cborg/lib/7float.js
  function decodeUndefined(_data, _pos, _minor, options) {
    if (options.allowUndefined === false) {
      throw new Error(`${decodeErrPrefix} undefined values are not supported`);
    } else if (options.coerceUndefinedToNull === true) {
      return new Token(Type.null, null, 1);
    }
    return new Token(Type.undefined, void 0, 1);
  }
  function decodeBreak(_data, _pos, _minor, options) {
    if (options.allowIndefinite === false) {
      throw new Error(`${decodeErrPrefix} indefinite length items not allowed`);
    }
    return new Token(Type.break, void 0, 1);
  }
  function createToken(value, bytes, options) {
    if (options) {
      if (options.allowNaN === false && Number.isNaN(value)) {
        throw new Error(`${decodeErrPrefix} NaN values are not supported`);
      }
      if (options.allowInfinity === false && (value === Infinity || value === -Infinity)) {
        throw new Error(`${decodeErrPrefix} Infinity values are not supported`);
      }
    }
    return new Token(Type.float, value, bytes);
  }
  function decodeFloat16(data, pos, _minor, options) {
    return createToken(readFloat16(data, pos + 1), 3, options);
  }
  function decodeFloat32(data, pos, _minor, options) {
    return createToken(readFloat32(data, pos + 1), 5, options);
  }
  function decodeFloat64(data, pos, _minor, options) {
    return createToken(readFloat64(data, pos + 1), 9, options);
  }
  function encodeFloat(writer, token, options) {
    const float = token.value;
    if (float === false) {
      writer.push([Type.float.majorEncoded | MINOR_FALSE]);
    } else if (float === true) {
      writer.push([Type.float.majorEncoded | MINOR_TRUE]);
    } else if (float === null) {
      writer.push([Type.float.majorEncoded | MINOR_NULL]);
    } else if (float === void 0) {
      writer.push([Type.float.majorEncoded | MINOR_UNDEFINED]);
    } else {
      let decoded;
      let success = false;
      if (!options || options.float64 !== true) {
        encodeFloat16(float);
        decoded = readFloat16(ui8a, 1);
        if (float === decoded || Number.isNaN(float)) {
          ui8a[0] = 249;
          writer.push(ui8a.slice(0, 3));
          success = true;
        } else {
          encodeFloat32(float);
          decoded = readFloat32(ui8a, 1);
          if (float === decoded) {
            ui8a[0] = 250;
            writer.push(ui8a.slice(0, 5));
            success = true;
          }
        }
      }
      if (!success) {
        encodeFloat64(float);
        decoded = readFloat64(ui8a, 1);
        ui8a[0] = 251;
        writer.push(ui8a.slice(0, 9));
      }
    }
  }
  function encodeFloat16(inp) {
    if (inp === Infinity) {
      dataView.setUint16(0, 31744, false);
    } else if (inp === -Infinity) {
      dataView.setUint16(0, 64512, false);
    } else if (Number.isNaN(inp)) {
      dataView.setUint16(0, 32256, false);
    } else {
      dataView.setFloat32(0, inp);
      const valu32 = dataView.getUint32(0);
      const exponent = (valu32 & 2139095040) >> 23;
      const mantissa = valu32 & 8388607;
      if (exponent === 255) {
        dataView.setUint16(0, 31744, false);
      } else if (exponent === 0) {
        dataView.setUint16(0, (inp & 2147483648) >> 16 | mantissa >> 13, false);
      } else {
        const logicalExponent = exponent - 127;
        if (logicalExponent < -24) {
          dataView.setUint16(0, 0);
        } else if (logicalExponent < -14) {
          dataView.setUint16(0, (valu32 & 2147483648) >> 16 | /* sign bit */
          1 << 24 + logicalExponent, false);
        } else {
          dataView.setUint16(0, (valu32 & 2147483648) >> 16 | logicalExponent + 15 << 10 | mantissa >> 13, false);
        }
      }
    }
  }
  function readFloat16(ui8a2, pos) {
    if (ui8a2.length - pos < 2) {
      throw new Error(`${decodeErrPrefix} not enough data for float16`);
    }
    const half = (ui8a2[pos] << 8) + ui8a2[pos + 1];
    if (half === 31744) {
      return Infinity;
    }
    if (half === 64512) {
      return -Infinity;
    }
    if (half === 32256) {
      return NaN;
    }
    const exp = half >> 10 & 31;
    const mant = half & 1023;
    let val;
    if (exp === 0) {
      val = mant * 2 ** -24;
    } else if (exp !== 31) {
      val = (mant + 1024) * 2 ** (exp - 25);
    } else {
      val = mant === 0 ? Infinity : NaN;
    }
    return half & 32768 ? -val : val;
  }
  function encodeFloat32(inp) {
    dataView.setFloat32(0, inp, false);
  }
  function readFloat32(ui8a2, pos) {
    if (ui8a2.length - pos < 4) {
      throw new Error(`${decodeErrPrefix} not enough data for float32`);
    }
    const offset = (ui8a2.byteOffset || 0) + pos;
    return new DataView(ui8a2.buffer, offset, 4).getFloat32(0, false);
  }
  function encodeFloat64(inp) {
    dataView.setFloat64(0, inp, false);
  }
  function readFloat64(ui8a2, pos) {
    if (ui8a2.length - pos < 8) {
      throw new Error(`${decodeErrPrefix} not enough data for float64`);
    }
    const offset = (ui8a2.byteOffset || 0) + pos;
    return new DataView(ui8a2.buffer, offset, 8).getFloat64(0, false);
  }
  var MINOR_FALSE, MINOR_TRUE, MINOR_NULL, MINOR_UNDEFINED, buffer, dataView, ui8a;
  var init_float = __esm({
    "node_modules/cborg/lib/7float.js"() {
      init_buffer_shim();
      init_token();
      init_common();
      init_uint();
      MINOR_FALSE = 20;
      MINOR_TRUE = 21;
      MINOR_NULL = 22;
      MINOR_UNDEFINED = 23;
      encodeFloat.encodedSize = function encodedSize8(token, options) {
        const float = token.value;
        if (float === false || float === true || float === null || float === void 0) {
          return 1;
        }
        if (!options || options.float64 !== true) {
          encodeFloat16(float);
          let decoded = readFloat16(ui8a, 1);
          if (float === decoded || Number.isNaN(float)) {
            return 3;
          }
          encodeFloat32(float);
          decoded = readFloat32(ui8a, 1);
          if (float === decoded) {
            return 5;
          }
        }
        return 9;
      };
      buffer = new ArrayBuffer(9);
      dataView = new DataView(buffer, 1);
      ui8a = new Uint8Array(buffer, 0);
      encodeFloat.compareTokens = encodeUint.compareTokens;
    }
  });

  // node_modules/cborg/lib/jump.js
  function invalidMinor(data, pos, minor) {
    throw new Error(`${decodeErrPrefix} encountered invalid minor (${minor}) for major ${data[pos] >>> 5}`);
  }
  function errorer(msg) {
    return () => {
      throw new Error(`${decodeErrPrefix} ${msg}`);
    };
  }
  function quickEncodeToken(token) {
    switch (token.type) {
      case Type.false:
        return fromArray([244]);
      case Type.true:
        return fromArray([245]);
      case Type.null:
        return fromArray([246]);
      case Type.bytes:
        if (!token.value.length) {
          return fromArray([64]);
        }
        return;
      case Type.string:
        if (token.value === "") {
          return fromArray([96]);
        }
        return;
      case Type.array:
        if (token.value === 0) {
          return fromArray([128]);
        }
        return;
      case Type.map:
        if (token.value === 0) {
          return fromArray([160]);
        }
        return;
      case Type.uint:
        if (token.value < 24) {
          return fromArray([Number(token.value)]);
        }
        return;
      case Type.negint:
        if (token.value >= -24) {
          return fromArray([31 - Number(token.value)]);
        }
    }
  }
  var jump, quick;
  var init_jump = __esm({
    "node_modules/cborg/lib/jump.js"() {
      init_buffer_shim();
      init_token();
      init_uint();
      init_negint();
      init_bytes();
      init_string();
      init_array();
      init_map();
      init_tag();
      init_float();
      init_common();
      init_byte_utils();
      jump = [];
      for (let i = 0; i <= 23; i++) {
        jump[i] = invalidMinor;
      }
      jump[24] = decodeUint8;
      jump[25] = decodeUint16;
      jump[26] = decodeUint32;
      jump[27] = decodeUint64;
      jump[28] = invalidMinor;
      jump[29] = invalidMinor;
      jump[30] = invalidMinor;
      jump[31] = invalidMinor;
      for (let i = 32; i <= 55; i++) {
        jump[i] = invalidMinor;
      }
      jump[56] = decodeNegint8;
      jump[57] = decodeNegint16;
      jump[58] = decodeNegint32;
      jump[59] = decodeNegint64;
      jump[60] = invalidMinor;
      jump[61] = invalidMinor;
      jump[62] = invalidMinor;
      jump[63] = invalidMinor;
      for (let i = 64; i <= 87; i++) {
        jump[i] = decodeBytesCompact;
      }
      jump[88] = decodeBytes8;
      jump[89] = decodeBytes16;
      jump[90] = decodeBytes32;
      jump[91] = decodeBytes64;
      jump[92] = invalidMinor;
      jump[93] = invalidMinor;
      jump[94] = invalidMinor;
      jump[95] = errorer("indefinite length bytes/strings are not supported");
      for (let i = 96; i <= 119; i++) {
        jump[i] = decodeStringCompact;
      }
      jump[120] = decodeString8;
      jump[121] = decodeString16;
      jump[122] = decodeString32;
      jump[123] = decodeString64;
      jump[124] = invalidMinor;
      jump[125] = invalidMinor;
      jump[126] = invalidMinor;
      jump[127] = errorer("indefinite length bytes/strings are not supported");
      for (let i = 128; i <= 151; i++) {
        jump[i] = decodeArrayCompact;
      }
      jump[152] = decodeArray8;
      jump[153] = decodeArray16;
      jump[154] = decodeArray32;
      jump[155] = decodeArray64;
      jump[156] = invalidMinor;
      jump[157] = invalidMinor;
      jump[158] = invalidMinor;
      jump[159] = decodeArrayIndefinite;
      for (let i = 160; i <= 183; i++) {
        jump[i] = decodeMapCompact;
      }
      jump[184] = decodeMap8;
      jump[185] = decodeMap16;
      jump[186] = decodeMap32;
      jump[187] = decodeMap64;
      jump[188] = invalidMinor;
      jump[189] = invalidMinor;
      jump[190] = invalidMinor;
      jump[191] = decodeMapIndefinite;
      for (let i = 192; i <= 215; i++) {
        jump[i] = decodeTagCompact;
      }
      jump[216] = decodeTag8;
      jump[217] = decodeTag16;
      jump[218] = decodeTag32;
      jump[219] = decodeTag64;
      jump[220] = invalidMinor;
      jump[221] = invalidMinor;
      jump[222] = invalidMinor;
      jump[223] = invalidMinor;
      for (let i = 224; i <= 243; i++) {
        jump[i] = errorer("simple values are not supported");
      }
      jump[244] = invalidMinor;
      jump[245] = invalidMinor;
      jump[246] = invalidMinor;
      jump[247] = decodeUndefined;
      jump[248] = errorer("simple values are not supported");
      jump[249] = decodeFloat16;
      jump[250] = decodeFloat32;
      jump[251] = decodeFloat64;
      jump[252] = invalidMinor;
      jump[253] = invalidMinor;
      jump[254] = invalidMinor;
      jump[255] = decodeBreak;
      quick = [];
      for (let i = 0; i < 24; i++) {
        quick[i] = new Token(Type.uint, i, 1);
      }
      for (let i = -1; i >= -24; i--) {
        quick[31 - i] = new Token(Type.negint, i, 1);
      }
      quick[64] = new Token(Type.bytes, new Uint8Array(0), 1);
      quick[96] = new Token(Type.string, "", 1);
      quick[128] = new Token(Type.array, 0, 1);
      quick[160] = new Token(Type.map, 0, 1);
      quick[244] = new Token(Type.false, false, 1);
      quick[245] = new Token(Type.true, true, 1);
      quick[246] = new Token(Type.null, null, 1);
    }
  });

  // node_modules/cborg/lib/encode.js
  function makeCborEncoders() {
    const encoders = [];
    encoders[Type.uint.major] = encodeUint;
    encoders[Type.negint.major] = encodeNegint;
    encoders[Type.bytes.major] = encodeBytes;
    encoders[Type.string.major] = encodeString;
    encoders[Type.array.major] = encodeArray;
    encoders[Type.map.major] = encodeMap;
    encoders[Type.tag.major] = encodeTag;
    encoders[Type.float.major] = encodeFloat;
    return encoders;
  }
  function objectToTokens(obj, options = {}, refStack) {
    const typ = is(obj);
    const customTypeEncoder = options && options.typeEncoders && /** @type {OptionalTypeEncoder} */
    options.typeEncoders[typ] || typeEncoders[typ];
    if (typeof customTypeEncoder === "function") {
      const tokens = customTypeEncoder(obj, typ, options, refStack);
      if (tokens != null) {
        return tokens;
      }
    }
    const typeEncoder = typeEncoders[typ];
    if (!typeEncoder) {
      throw new Error(`${encodeErrPrefix} unsupported type: ${typ}`);
    }
    return typeEncoder(obj, typ, options, refStack);
  }
  function sortMapEntries(entries, options) {
    if (options.mapSorter) {
      entries.sort(options.mapSorter);
    }
  }
  function mapSorter(e1, e2) {
    const keyToken1 = Array.isArray(e1[0]) ? e1[0][0] : e1[0];
    const keyToken2 = Array.isArray(e2[0]) ? e2[0][0] : e2[0];
    if (keyToken1.type !== keyToken2.type) {
      return keyToken1.type.compare(keyToken2.type);
    }
    const major = keyToken1.type.major;
    const tcmp = cborEncoders[major].compareTokens(keyToken1, keyToken2);
    if (tcmp === 0) {
      console.warn("WARNING: complex key types used, CBOR key sorting guarantees are gone");
    }
    return tcmp;
  }
  function rfc8949MapSorter(e1, e2) {
    if (e1[0] instanceof Token && e2[0] instanceof Token) {
      const t1 = (
        /** @type {TokenEx} */
        e1[0]
      );
      const t2 = (
        /** @type {TokenEx} */
        e2[0]
      );
      if (!t1._keyBytes) {
        t1._keyBytes = encodeRfc8949(t1.value);
      }
      if (!t2._keyBytes) {
        t2._keyBytes = encodeRfc8949(t2.value);
      }
      return compare(t1._keyBytes, t2._keyBytes);
    }
    throw new Error("rfc8949MapSorter: complex key types are not supported yet");
  }
  function encodeRfc8949(data) {
    return encodeCustom(data, cborEncoders, rfc8949EncodeOptions);
  }
  function tokensToEncoded(writer, tokens, encoders, options) {
    if (Array.isArray(tokens)) {
      for (const token of tokens) {
        tokensToEncoded(writer, token, encoders, options);
      }
    } else {
      encoders[tokens.type.major](writer, tokens, options);
    }
  }
  function canDirectEncode(options) {
    return options.addBreakTokens !== true;
  }
  function directEncode(writer, data, options, refStack) {
    const typ = is(data);
    const customEncoder = options.typeEncoders && options.typeEncoders[typ];
    if (customEncoder) {
      const tokens = customEncoder(data, typ, options, refStack);
      if (tokens != null) {
        tokensToEncoded(writer, tokens, cborEncoders, options);
        return;
      }
    }
    switch (typ) {
      case "null":
        writer.push([SIMPLE_NULL]);
        return;
      case "undefined":
        writer.push([SIMPLE_UNDEFINED]);
        return;
      case "boolean":
        writer.push([data ? SIMPLE_TRUE : SIMPLE_FALSE]);
        return;
      case "number":
        if (!Number.isInteger(data) || !Number.isSafeInteger(data)) {
          encodeFloat(writer, new Token(Type.float, data), options);
        } else if (data >= 0) {
          encodeUintValue(writer, MAJOR_UINT, data);
        } else {
          encodeUintValue(writer, MAJOR_NEGINT, data * -1 - 1);
        }
        return;
      case "bigint":
        if (data >= BigInt(0)) {
          encodeUintValue(writer, MAJOR_UINT, data);
        } else {
          encodeUintValue(writer, MAJOR_NEGINT, data * neg1b2 - pos1b2);
        }
        return;
      case "string": {
        const bytes = fromString(data);
        encodeUintValue(writer, MAJOR_STRING, bytes.length);
        writer.push(bytes);
        return;
      }
      case "Uint8Array":
        encodeUintValue(writer, MAJOR_BYTES, data.length);
        writer.push(data);
        return;
      case "Array":
        if (!data.length) {
          writer.push([MAJOR_ARRAY]);
          return;
        }
        refStack = Ref.createCheck(refStack, data);
        encodeUintValue(writer, MAJOR_ARRAY, data.length);
        for (const elem of data) {
          directEncode(writer, elem, options, refStack);
        }
        return;
      case "Object":
      case "Map":
        {
          const tokens = typeEncoders.Object(data, typ, options, refStack);
          tokensToEncoded(writer, tokens, cborEncoders, options);
        }
        return;
      default: {
        const typeEncoder = typeEncoders[typ];
        if (!typeEncoder) {
          throw new Error(`${encodeErrPrefix} unsupported type: ${typ}`);
        }
        const tokens = typeEncoder(data, typ, options, refStack);
        tokensToEncoded(writer, tokens, cborEncoders, options);
      }
    }
  }
  function encodeCustom(data, encoders, options, destination) {
    const hasDest = destination instanceof Uint8Array;
    let writeTo = hasDest ? new U8Bl(destination) : defaultWriter;
    const tokens = objectToTokens(data, options);
    if (!Array.isArray(tokens) && options.quickEncodeToken) {
      const quickBytes = options.quickEncodeToken(tokens);
      if (quickBytes) {
        if (hasDest) {
          writeTo.push(quickBytes);
          return writeTo.toBytes();
        }
        return quickBytes;
      }
      const encoder = encoders[tokens.type.major];
      if (encoder.encodedSize) {
        const size = encoder.encodedSize(tokens, options);
        if (!hasDest) {
          writeTo = new Bl(size);
        }
        encoder(writeTo, tokens, options);
        if (writeTo.chunks.length !== 1) {
          throw new Error(`Unexpected error: pre-calculated length for ${tokens} was wrong`);
        }
        return hasDest ? writeTo.toBytes() : asU8A(writeTo.chunks[0]);
      }
    }
    writeTo.reset();
    tokensToEncoded(writeTo, tokens, encoders, options);
    return writeTo.toBytes(true);
  }
  function encode(data, options) {
    options = Object.assign({}, defaultEncodeOptions, options);
    if (canDirectEncode(options)) {
      defaultWriter.reset();
      directEncode(defaultWriter, data, options, void 0);
      return defaultWriter.toBytes(true);
    }
    return encodeCustom(data, cborEncoders, options);
  }
  var defaultEncodeOptions, rfc8949EncodeOptions, cborEncoders, defaultWriter, Ref, simpleTokens, typeEncoders, MAJOR_UINT, MAJOR_NEGINT, MAJOR_BYTES, MAJOR_STRING, MAJOR_ARRAY, SIMPLE_FALSE, SIMPLE_TRUE, SIMPLE_NULL, SIMPLE_UNDEFINED, neg1b2, pos1b2;
  var init_encode = __esm({
    "node_modules/cborg/lib/encode.js"() {
      init_buffer_shim();
      init_is();
      init_token();
      init_bl();
      init_common();
      init_jump();
      init_byte_utils();
      init_uint();
      init_negint();
      init_bytes();
      init_string();
      init_array();
      init_map();
      init_tag();
      init_float();
      defaultEncodeOptions = {
        float64: false,
        mapSorter,
        quickEncodeToken
      };
      rfc8949EncodeOptions = Object.freeze({
        float64: true,
        mapSorter: rfc8949MapSorter,
        quickEncodeToken
      });
      cborEncoders = makeCborEncoders();
      defaultWriter = new Bl();
      Ref = class _Ref {
        /**
         * @param {object|any[]} obj
         * @param {Reference|undefined} parent
         */
        constructor(obj, parent) {
          this.obj = obj;
          this.parent = parent;
        }
        /**
         * @param {object|any[]} obj
         * @returns {boolean}
         */
        includes(obj) {
          let p = this;
          do {
            if (p.obj === obj) {
              return true;
            }
          } while (p = p.parent);
          return false;
        }
        /**
         * @param {Reference|undefined} stack
         * @param {object|any[]} obj
         * @returns {Reference}
         */
        static createCheck(stack, obj) {
          if (stack && stack.includes(obj)) {
            throw new Error(`${encodeErrPrefix} object contains circular references`);
          }
          return new _Ref(obj, stack);
        }
      };
      simpleTokens = {
        null: new Token(Type.null, null),
        undefined: new Token(Type.undefined, void 0),
        true: new Token(Type.true, true),
        false: new Token(Type.false, false),
        emptyArray: new Token(Type.array, 0),
        emptyMap: new Token(Type.map, 0)
      };
      typeEncoders = {
        /**
         * @param {any} obj
         * @param {string} _typ
         * @param {EncodeOptions} _options
         * @param {Reference} [_refStack]
         * @returns {TokenOrNestedTokens}
         */
        number(obj, _typ, _options, _refStack) {
          if (!Number.isInteger(obj) || !Number.isSafeInteger(obj)) {
            return new Token(Type.float, obj);
          } else if (obj >= 0) {
            return new Token(Type.uint, obj);
          } else {
            return new Token(Type.negint, obj);
          }
        },
        /**
         * @param {any} obj
         * @param {string} _typ
         * @param {EncodeOptions} _options
         * @param {Reference} [_refStack]
         * @returns {TokenOrNestedTokens}
         */
        bigint(obj, _typ, _options, _refStack) {
          if (obj >= BigInt(0)) {
            return new Token(Type.uint, obj);
          } else {
            return new Token(Type.negint, obj);
          }
        },
        /**
         * @param {any} obj
         * @param {string} _typ
         * @param {EncodeOptions} _options
         * @param {Reference} [_refStack]
         * @returns {TokenOrNestedTokens}
         */
        Uint8Array(obj, _typ, _options, _refStack) {
          return new Token(Type.bytes, obj);
        },
        /**
         * @param {any} obj
         * @param {string} _typ
         * @param {EncodeOptions} _options
         * @param {Reference} [_refStack]
         * @returns {TokenOrNestedTokens}
         */
        string(obj, _typ, _options, _refStack) {
          return new Token(Type.string, obj);
        },
        /**
         * @param {any} obj
         * @param {string} _typ
         * @param {EncodeOptions} _options
         * @param {Reference} [_refStack]
         * @returns {TokenOrNestedTokens}
         */
        boolean(obj, _typ, _options, _refStack) {
          return obj ? simpleTokens.true : simpleTokens.false;
        },
        /**
         * @param {any} _obj
         * @param {string} _typ
         * @param {EncodeOptions} _options
         * @param {Reference} [_refStack]
         * @returns {TokenOrNestedTokens}
         */
        null(_obj, _typ, _options, _refStack) {
          return simpleTokens.null;
        },
        /**
         * @param {any} _obj
         * @param {string} _typ
         * @param {EncodeOptions} _options
         * @param {Reference} [_refStack]
         * @returns {TokenOrNestedTokens}
         */
        undefined(_obj, _typ, _options, _refStack) {
          return simpleTokens.undefined;
        },
        /**
         * @param {any} obj
         * @param {string} _typ
         * @param {EncodeOptions} _options
         * @param {Reference} [_refStack]
         * @returns {TokenOrNestedTokens}
         */
        ArrayBuffer(obj, _typ, _options, _refStack) {
          return new Token(Type.bytes, new Uint8Array(obj));
        },
        /**
         * @param {any} obj
         * @param {string} _typ
         * @param {EncodeOptions} _options
         * @param {Reference} [_refStack]
         * @returns {TokenOrNestedTokens}
         */
        DataView(obj, _typ, _options, _refStack) {
          return new Token(Type.bytes, new Uint8Array(obj.buffer, obj.byteOffset, obj.byteLength));
        },
        /**
         * @param {any} obj
         * @param {string} _typ
         * @param {EncodeOptions} options
         * @param {Reference} [refStack]
         * @returns {TokenOrNestedTokens}
         */
        Array(obj, _typ, options, refStack) {
          if (!obj.length) {
            if (options.addBreakTokens === true) {
              return [simpleTokens.emptyArray, new Token(Type.break)];
            }
            return simpleTokens.emptyArray;
          }
          refStack = Ref.createCheck(refStack, obj);
          const entries = [];
          let i = 0;
          for (const e of obj) {
            entries[i++] = objectToTokens(e, options, refStack);
          }
          if (options.addBreakTokens) {
            return [new Token(Type.array, obj.length), entries, new Token(Type.break)];
          }
          return [new Token(Type.array, obj.length), entries];
        },
        /**
         * @param {any} obj
         * @param {string} typ
         * @param {EncodeOptions} options
         * @param {Reference} [refStack]
         * @returns {TokenOrNestedTokens}
         */
        Object(obj, typ, options, refStack) {
          const isMap = typ !== "Object";
          const keys = isMap ? obj.keys() : Object.keys(obj);
          const maxLength = isMap ? obj.size : keys.length;
          let entries;
          if (maxLength) {
            entries = new Array(maxLength);
            refStack = Ref.createCheck(refStack, obj);
            const skipUndefined = !isMap && options.ignoreUndefinedProperties;
            let i = 0;
            for (const key of keys) {
              const value = isMap ? obj.get(key) : obj[key];
              if (skipUndefined && value === void 0) {
                continue;
              }
              entries[i++] = [
                objectToTokens(key, options, refStack),
                objectToTokens(value, options, refStack)
              ];
            }
            if (i < maxLength) {
              entries.length = i;
            }
          }
          if (!(entries == null ? void 0 : entries.length)) {
            if (options.addBreakTokens === true) {
              return [simpleTokens.emptyMap, new Token(Type.break)];
            }
            return simpleTokens.emptyMap;
          }
          sortMapEntries(entries, options);
          if (options.addBreakTokens) {
            return [new Token(Type.map, entries.length), entries, new Token(Type.break)];
          }
          return [new Token(Type.map, entries.length), entries];
        }
      };
      typeEncoders.Map = typeEncoders.Object;
      typeEncoders.Buffer = typeEncoders.Uint8Array;
      for (const typ of "Uint8Clamped Uint16 Uint32 Int8 Int16 Int32 BigUint64 BigInt64 Float32 Float64".split(" ")) {
        typeEncoders[`${typ}Array`] = typeEncoders.DataView;
      }
      MAJOR_UINT = Type.uint.majorEncoded;
      MAJOR_NEGINT = Type.negint.majorEncoded;
      MAJOR_BYTES = Type.bytes.majorEncoded;
      MAJOR_STRING = Type.string.majorEncoded;
      MAJOR_ARRAY = Type.array.majorEncoded;
      SIMPLE_FALSE = Type.float.majorEncoded | MINOR_FALSE;
      SIMPLE_TRUE = Type.float.majorEncoded | MINOR_TRUE;
      SIMPLE_NULL = Type.float.majorEncoded | MINOR_NULL;
      SIMPLE_UNDEFINED = Type.float.majorEncoded | MINOR_UNDEFINED;
      neg1b2 = BigInt(-1);
      pos1b2 = BigInt(1);
    }
  });

  // node_modules/cborg/lib/decode.js
  function tokenToArray(token, tokeniser, options) {
    const arr = [];
    for (let i = 0; i < token.value; i++) {
      const value = tokensToObject(tokeniser, options);
      if (value === BREAK) {
        if (token.value === Infinity) {
          break;
        }
        throw new Error(`${decodeErrPrefix} got unexpected break to lengthed array`);
      }
      if (value === DONE) {
        throw new Error(`${decodeErrPrefix} found array but not enough entries (got ${i}, expected ${token.value})`);
      }
      arr[i] = value;
    }
    return arr;
  }
  function tokenToMap(token, tokeniser, options) {
    const useMaps = options.useMaps === true;
    const rejectDuplicateMapKeys = options.rejectDuplicateMapKeys === true;
    const obj = useMaps ? void 0 : {};
    const m = useMaps ? /* @__PURE__ */ new Map() : void 0;
    for (let i = 0; i < token.value; i++) {
      const key = tokensToObject(tokeniser, options);
      if (key === BREAK) {
        if (token.value === Infinity) {
          break;
        }
        throw new Error(`${decodeErrPrefix} got unexpected break to lengthed map`);
      }
      if (key === DONE) {
        throw new Error(`${decodeErrPrefix} found map but not enough entries (got ${i} [no key], expected ${token.value})`);
      }
      if (!useMaps && typeof key !== "string") {
        throw new Error(`${decodeErrPrefix} non-string keys not supported (got ${typeof key})`);
      }
      if (rejectDuplicateMapKeys) {
        if (useMaps && m.has(key) || !useMaps && Object.hasOwn(obj, key)) {
          throw new Error(`${decodeErrPrefix} found repeat map key "${key}"`);
        }
      }
      const value = tokensToObject(tokeniser, options);
      if (value === DONE) {
        throw new Error(`${decodeErrPrefix} found map but not enough entries (got ${i} [no value], expected ${token.value})`);
      }
      if (useMaps) {
        m.set(key, value);
      } else {
        obj[key] = value;
      }
    }
    return useMaps ? m : obj;
  }
  function tokensToObject(tokeniser, options) {
    if (tokeniser.done()) {
      return DONE;
    }
    const token = tokeniser.next();
    if (Type.equals(token.type, Type.break)) {
      return BREAK;
    }
    if (token.type.terminal) {
      return token.value;
    }
    if (Type.equals(token.type, Type.array)) {
      return tokenToArray(token, tokeniser, options);
    }
    if (Type.equals(token.type, Type.map)) {
      return tokenToMap(token, tokeniser, options);
    }
    if (Type.equals(token.type, Type.tag)) {
      if (options.tags && typeof options.tags[token.value] === "function") {
        const tagged = tokensToObject(tokeniser, options);
        return options.tags[token.value](tagged);
      }
      throw new Error(`${decodeErrPrefix} tag not supported (${token.value})`);
    }
    throw new Error("unsupported");
  }
  function decodeFirst(data, options) {
    if (!(data instanceof Uint8Array)) {
      throw new Error(`${decodeErrPrefix} data to decode must be a Uint8Array`);
    }
    options = Object.assign({}, defaultDecodeOptions, options);
    const u8aData = asU8A(data);
    const tokeniser = options.tokenizer || new Tokeniser(u8aData, options);
    const decoded = tokensToObject(tokeniser, options);
    if (decoded === DONE) {
      throw new Error(`${decodeErrPrefix} did not find any content to decode`);
    }
    if (decoded === BREAK) {
      throw new Error(`${decodeErrPrefix} got unexpected break`);
    }
    return [decoded, data.subarray(tokeniser.pos())];
  }
  function decode(data, options) {
    const [decoded, remainder] = decodeFirst(data, options);
    if (remainder.length > 0) {
      throw new Error(`${decodeErrPrefix} too many terminals, data makes no sense`);
    }
    return decoded;
  }
  var defaultDecodeOptions, Tokeniser, DONE, BREAK;
  var init_decode = __esm({
    "node_modules/cborg/lib/decode.js"() {
      init_buffer_shim();
      init_common();
      init_token();
      init_jump();
      init_byte_utils();
      defaultDecodeOptions = {
        strict: false,
        allowIndefinite: true,
        allowUndefined: true,
        allowBigInt: true
      };
      Tokeniser = class {
        /**
         * @param {Uint8Array} data
         * @param {DecodeOptions} options
         */
        constructor(data, options = {}) {
          this._pos = 0;
          this.data = data;
          this.options = options;
        }
        pos() {
          return this._pos;
        }
        done() {
          return this._pos >= this.data.length;
        }
        next() {
          const byt = this.data[this._pos];
          let token = quick[byt];
          if (token === void 0) {
            const decoder = jump[byt];
            if (!decoder) {
              throw new Error(`${decodeErrPrefix} no decoder for major type ${byt >>> 5} (byte 0x${byt.toString(16).padStart(2, "0")})`);
            }
            const minor = byt & 31;
            token = decoder(this.data, this._pos, minor, this.options);
          }
          this._pos += token.encodedLength;
          return token;
        }
      };
      DONE = Symbol.for("DONE");
      BREAK = Symbol.for("BREAK");
    }
  });

  // node_modules/cborg/cborg.js
  var init_cborg = __esm({
    "node_modules/cborg/cborg.js"() {
      init_buffer_shim();
      init_encode();
      init_decode();
      init_token();
    }
  });

  // node_modules/@gandlaf21/bc-ur/dist/lib/es6/cbor.js
  var import_buffer3, cborEncode, cborDecode;
  var init_cbor = __esm({
    "node_modules/@gandlaf21/bc-ur/dist/lib/es6/cbor.js"() {
      init_buffer_shim();
      import_buffer3 = __toESM(require_buffer());
      init_cborg();
      cborEncode = function(data) {
        return import_buffer3.Buffer.from(encode(data));
      };
      cborDecode = function(data) {
        return decode(import_buffer3.Buffer.isBuffer(data) ? data : import_buffer3.Buffer.from(data, "hex"));
      };
    }
  });

  // node_modules/@gandlaf21/bc-ur/dist/lib/es6/ur.js
  var import_buffer4, UR, ur_default;
  var init_ur = __esm({
    "node_modules/@gandlaf21/bc-ur/dist/lib/es6/ur.js"() {
      init_buffer_shim();
      init_errors();
      init_utils2();
      init_cbor();
      import_buffer4 = __toESM(require_buffer());
      UR = /** @class */
      (function() {
        function UR2(_cborPayload, _type) {
          if (_type === void 0) {
            _type = "bytes";
          }
          this._cborPayload = _cborPayload;
          this._type = _type;
          if (!isURType(this._type)) {
            throw new InvalidTypeError();
          }
        }
        UR2.fromBuffer = function(buf) {
          return new UR2(cborEncode(buf));
        };
        UR2.from = function(value) {
          return UR2.fromBuffer(import_buffer4.Buffer.from(value));
        };
        UR2.prototype.decodeCBOR = function() {
          return cborDecode(this._cborPayload);
        };
        Object.defineProperty(UR2.prototype, "type", {
          get: function() {
            return this._type;
          },
          enumerable: false,
          configurable: true
        });
        Object.defineProperty(UR2.prototype, "cbor", {
          get: function() {
            return this._cborPayload;
          },
          enumerable: false,
          configurable: true
        });
        UR2.prototype.equals = function(ur2) {
          return this.type === ur2.type && this.cbor.equals(ur2.cbor);
        };
        return UR2;
      })();
      ur_default = UR;
    }
  });

  // node_modules/bignumber.js/bignumber.mjs
  function clone(configObject) {
    var div, convertBase, parseNumeric, P = BigNumber2.prototype = { constructor: BigNumber2, toString: null, valueOf: null }, ONE = new BigNumber2(1), DECIMAL_PLACES = 20, ROUNDING_MODE = 4, TO_EXP_NEG = -7, TO_EXP_POS = 21, MIN_EXP = -1e7, MAX_EXP = 1e7, CRYPTO = false, MODULO_MODE = 1, POW_PRECISION = 0, FORMAT = {
      prefix: "",
      groupSize: 3,
      secondaryGroupSize: 0,
      groupSeparator: ",",
      decimalSeparator: ".",
      fractionGroupSize: 0,
      fractionGroupSeparator: "\xA0",
      // non-breaking space
      suffix: ""
    }, ALPHABET = "0123456789abcdefghijklmnopqrstuvwxyz", alphabetHasNormalDecimalDigits = true;
    function BigNumber2(v, b) {
      var alphabet, c, caseChanged, e, i, isNum, len, str, x = this;
      if (!(x instanceof BigNumber2)) return new BigNumber2(v, b);
      if (b == null) {
        if (v && v._isBigNumber === true) {
          x.s = v.s;
          if (!v.c || v.e > MAX_EXP) {
            x.c = x.e = null;
          } else if (v.e < MIN_EXP) {
            x.c = [x.e = 0];
          } else {
            x.e = v.e;
            x.c = v.c.slice();
          }
          return;
        }
        if ((isNum = typeof v == "number") && v * 0 == 0) {
          x.s = 1 / v < 0 ? (v = -v, -1) : 1;
          if (v === ~~v) {
            for (e = 0, i = v; i >= 10; i /= 10, e++) ;
            if (e > MAX_EXP) {
              x.c = x.e = null;
            } else {
              x.e = e;
              x.c = [v];
            }
            return;
          }
          str = String(v);
        } else {
          if (!isNumeric.test(str = String(v))) return parseNumeric(x, str, isNum);
          x.s = str.charCodeAt(0) == 45 ? (str = str.slice(1), -1) : 1;
        }
        if ((e = str.indexOf(".")) > -1) str = str.replace(".", "");
        if ((i = str.search(/e/i)) > 0) {
          if (e < 0) e = i;
          e += +str.slice(i + 1);
          str = str.substring(0, i);
        } else if (e < 0) {
          e = str.length;
        }
      } else {
        intCheck(b, 2, ALPHABET.length, "Base");
        if (b == 10 && alphabetHasNormalDecimalDigits) {
          x = new BigNumber2(v);
          return round(x, DECIMAL_PLACES + x.e + 1, ROUNDING_MODE);
        }
        str = String(v);
        if (isNum = typeof v == "number") {
          if (v * 0 != 0) return parseNumeric(x, str, isNum, b);
          x.s = 1 / v < 0 ? (str = str.slice(1), -1) : 1;
          if (BigNumber2.DEBUG && str.replace(/^0\.0*|\./, "").length > 15) {
            throw Error(tooManyDigits + v);
          }
        } else {
          x.s = str.charCodeAt(0) === 45 ? (str = str.slice(1), -1) : 1;
        }
        alphabet = ALPHABET.slice(0, b);
        e = i = 0;
        for (len = str.length; i < len; i++) {
          if (alphabet.indexOf(c = str.charAt(i)) < 0) {
            if (c == ".") {
              if (i > e) {
                e = len;
                continue;
              }
            } else if (!caseChanged) {
              if (str == str.toUpperCase() && (str = str.toLowerCase()) || str == str.toLowerCase() && (str = str.toUpperCase())) {
                caseChanged = true;
                i = -1;
                e = 0;
                continue;
              }
            }
            return parseNumeric(x, String(v), isNum, b);
          }
        }
        isNum = false;
        str = convertBase(str, b, 10, x.s);
        if ((e = str.indexOf(".")) > -1) str = str.replace(".", "");
        else e = str.length;
      }
      for (i = 0; str.charCodeAt(i) === 48; i++) ;
      for (len = str.length; str.charCodeAt(--len) === 48; ) ;
      if (str = str.slice(i, ++len)) {
        len -= i;
        if (isNum && BigNumber2.DEBUG && len > 15 && (v > MAX_SAFE_INTEGER || v !== mathfloor(v))) {
          throw Error(tooManyDigits + x.s * v);
        }
        if ((e = e - i - 1) > MAX_EXP) {
          x.c = x.e = null;
        } else if (e < MIN_EXP) {
          x.c = [x.e = 0];
        } else {
          x.e = e;
          x.c = [];
          i = (e + 1) % LOG_BASE;
          if (e < 0) i += LOG_BASE;
          if (i < len) {
            if (i) x.c.push(+str.slice(0, i));
            for (len -= LOG_BASE; i < len; ) {
              x.c.push(+str.slice(i, i += LOG_BASE));
            }
            i = LOG_BASE - (str = str.slice(i)).length;
          } else {
            i -= len;
          }
          for (; i--; str += "0") ;
          x.c.push(+str);
        }
      } else {
        x.c = [x.e = 0];
      }
    }
    BigNumber2.clone = clone;
    BigNumber2.ROUND_UP = 0;
    BigNumber2.ROUND_DOWN = 1;
    BigNumber2.ROUND_CEIL = 2;
    BigNumber2.ROUND_FLOOR = 3;
    BigNumber2.ROUND_HALF_UP = 4;
    BigNumber2.ROUND_HALF_DOWN = 5;
    BigNumber2.ROUND_HALF_EVEN = 6;
    BigNumber2.ROUND_HALF_CEIL = 7;
    BigNumber2.ROUND_HALF_FLOOR = 8;
    BigNumber2.EUCLID = 9;
    BigNumber2.config = BigNumber2.set = function(obj) {
      var p, v;
      if (obj != null) {
        if (typeof obj == "object") {
          if (obj.hasOwnProperty(p = "DECIMAL_PLACES")) {
            v = obj[p];
            intCheck(v, 0, MAX, p);
            DECIMAL_PLACES = v;
          }
          if (obj.hasOwnProperty(p = "ROUNDING_MODE")) {
            v = obj[p];
            intCheck(v, 0, 8, p);
            ROUNDING_MODE = v;
          }
          if (obj.hasOwnProperty(p = "EXPONENTIAL_AT")) {
            v = obj[p];
            if (v && v.pop) {
              intCheck(v[0], -MAX, 0, p);
              intCheck(v[1], 0, MAX, p);
              TO_EXP_NEG = v[0];
              TO_EXP_POS = v[1];
            } else {
              intCheck(v, -MAX, MAX, p);
              TO_EXP_NEG = -(TO_EXP_POS = v < 0 ? -v : v);
            }
          }
          if (obj.hasOwnProperty(p = "RANGE")) {
            v = obj[p];
            if (v && v.pop) {
              intCheck(v[0], -MAX, -1, p);
              intCheck(v[1], 1, MAX, p);
              MIN_EXP = v[0];
              MAX_EXP = v[1];
            } else {
              intCheck(v, -MAX, MAX, p);
              if (v) {
                MIN_EXP = -(MAX_EXP = v < 0 ? -v : v);
              } else {
                throw Error(bignumberError + p + " cannot be zero: " + v);
              }
            }
          }
          if (obj.hasOwnProperty(p = "CRYPTO")) {
            v = obj[p];
            if (v === !!v) {
              if (v) {
                if (typeof crypto != "undefined" && crypto && (crypto.getRandomValues || crypto.randomBytes)) {
                  CRYPTO = v;
                } else {
                  CRYPTO = !v;
                  throw Error(bignumberError + "crypto unavailable");
                }
              } else {
                CRYPTO = v;
              }
            } else {
              throw Error(bignumberError + p + " not true or false: " + v);
            }
          }
          if (obj.hasOwnProperty(p = "MODULO_MODE")) {
            v = obj[p];
            intCheck(v, 0, 9, p);
            MODULO_MODE = v;
          }
          if (obj.hasOwnProperty(p = "POW_PRECISION")) {
            v = obj[p];
            intCheck(v, 0, MAX, p);
            POW_PRECISION = v;
          }
          if (obj.hasOwnProperty(p = "FORMAT")) {
            v = obj[p];
            if (typeof v == "object") FORMAT = v;
            else throw Error(bignumberError + p + " not an object: " + v);
          }
          if (obj.hasOwnProperty(p = "ALPHABET")) {
            v = obj[p];
            if (typeof v == "string" && !/^.?$|[+\-.\s]|(.).*\1/.test(v)) {
              alphabetHasNormalDecimalDigits = v.slice(0, 10) == "0123456789";
              ALPHABET = v;
            } else {
              throw Error(bignumberError + p + " invalid: " + v);
            }
          }
        } else {
          throw Error(bignumberError + "Object expected: " + obj);
        }
      }
      return {
        DECIMAL_PLACES,
        ROUNDING_MODE,
        EXPONENTIAL_AT: [TO_EXP_NEG, TO_EXP_POS],
        RANGE: [MIN_EXP, MAX_EXP],
        CRYPTO,
        MODULO_MODE,
        POW_PRECISION,
        FORMAT,
        ALPHABET
      };
    };
    BigNumber2.isBigNumber = function(v) {
      if (!v || v._isBigNumber !== true) return false;
      if (!BigNumber2.DEBUG) return true;
      var i, n, c = v.c, e = v.e, s = v.s;
      out: if ({}.toString.call(c) == "[object Array]") {
        if ((s === 1 || s === -1) && e >= -MAX && e <= MAX && e === mathfloor(e)) {
          if (c[0] === 0) {
            if (e === 0 && c.length === 1) return true;
            break out;
          }
          i = (e + 1) % LOG_BASE;
          if (i < 1) i += LOG_BASE;
          if (String(c[0]).length == i) {
            for (i = 0; i < c.length; i++) {
              n = c[i];
              if (n < 0 || n >= BASE || n !== mathfloor(n)) break out;
            }
            if (n !== 0) return true;
          }
        }
      } else if (c === null && e === null && (s === null || s === 1 || s === -1)) {
        return true;
      }
      throw Error(bignumberError + "Invalid BigNumber: " + v);
    };
    BigNumber2.maximum = BigNumber2.max = function() {
      return maxOrMin(arguments, -1);
    };
    BigNumber2.minimum = BigNumber2.min = function() {
      return maxOrMin(arguments, 1);
    };
    BigNumber2.random = (function() {
      var pow2_53 = 9007199254740992;
      var random53bitInt = Math.random() * pow2_53 & 2097151 ? function() {
        return mathfloor(Math.random() * pow2_53);
      } : function() {
        return (Math.random() * 1073741824 | 0) * 8388608 + (Math.random() * 8388608 | 0);
      };
      return function(dp) {
        var a, b, e, k, v, i = 0, c = [], rand = new BigNumber2(ONE);
        if (dp == null) dp = DECIMAL_PLACES;
        else intCheck(dp, 0, MAX);
        k = mathceil(dp / LOG_BASE);
        if (CRYPTO) {
          if (crypto.getRandomValues) {
            a = crypto.getRandomValues(new Uint32Array(k *= 2));
            for (; i < k; ) {
              v = a[i] * 131072 + (a[i + 1] >>> 11);
              if (v >= 9e15) {
                b = crypto.getRandomValues(new Uint32Array(2));
                a[i] = b[0];
                a[i + 1] = b[1];
              } else {
                c.push(v % 1e14);
                i += 2;
              }
            }
            i = k / 2;
          } else if (crypto.randomBytes) {
            a = crypto.randomBytes(k *= 7);
            for (; i < k; ) {
              v = (a[i] & 31) * 281474976710656 + a[i + 1] * 1099511627776 + a[i + 2] * 4294967296 + a[i + 3] * 16777216 + (a[i + 4] << 16) + (a[i + 5] << 8) + a[i + 6];
              if (v >= 9e15) {
                crypto.randomBytes(7).copy(a, i);
              } else {
                c.push(v % 1e14);
                i += 7;
              }
            }
            i = k / 7;
          } else {
            CRYPTO = false;
            throw Error(bignumberError + "crypto unavailable");
          }
        }
        if (!CRYPTO) {
          for (; i < k; ) {
            v = random53bitInt();
            if (v < 9e15) c[i++] = v % 1e14;
          }
        }
        k = c[--i];
        dp %= LOG_BASE;
        if (k && dp) {
          v = POWS_TEN[LOG_BASE - dp];
          c[i] = mathfloor(k / v) * v;
        }
        for (; c[i] === 0; c.pop(), i--) ;
        if (i < 0) {
          c = [e = 0];
        } else {
          for (e = -1; c[0] === 0; c.splice(0, 1), e -= LOG_BASE) ;
          for (i = 1, v = c[0]; v >= 10; v /= 10, i++) ;
          if (i < LOG_BASE) e -= LOG_BASE - i;
        }
        rand.e = e;
        rand.c = c;
        return rand;
      };
    })();
    BigNumber2.sum = function() {
      var i = 1, args = arguments, sum = new BigNumber2(args[0]);
      for (; i < args.length; ) sum = sum.plus(args[i++]);
      return sum;
    };
    convertBase = /* @__PURE__ */ (function() {
      var decimal = "0123456789";
      function toBaseOut(str, baseIn, baseOut, alphabet) {
        var j, arr = [0], arrL, i = 0, len = str.length;
        for (; i < len; ) {
          for (arrL = arr.length; arrL--; arr[arrL] *= baseIn) ;
          arr[0] += alphabet.indexOf(str.charAt(i++));
          for (j = 0; j < arr.length; j++) {
            if (arr[j] > baseOut - 1) {
              if (arr[j + 1] == null) arr[j + 1] = 0;
              arr[j + 1] += arr[j] / baseOut | 0;
              arr[j] %= baseOut;
            }
          }
        }
        return arr.reverse();
      }
      return function(str, baseIn, baseOut, sign, callerIsToString) {
        var alphabet, d, e, k, r, x, xc, y, i = str.indexOf("."), dp = DECIMAL_PLACES, rm = ROUNDING_MODE;
        if (i >= 0) {
          k = POW_PRECISION;
          POW_PRECISION = 0;
          str = str.replace(".", "");
          y = new BigNumber2(baseIn);
          x = y.pow(str.length - i);
          POW_PRECISION = k;
          y.c = toBaseOut(
            toFixedPoint(coeffToString(x.c), x.e, "0"),
            10,
            baseOut,
            decimal
          );
          y.e = y.c.length;
        }
        xc = toBaseOut(str, baseIn, baseOut, callerIsToString ? (alphabet = ALPHABET, decimal) : (alphabet = decimal, ALPHABET));
        e = k = xc.length;
        for (; xc[--k] == 0; xc.pop()) ;
        if (!xc[0]) return alphabet.charAt(0);
        if (i < 0) {
          --e;
        } else {
          x.c = xc;
          x.e = e;
          x.s = sign;
          x = div(x, y, dp, rm, baseOut);
          xc = x.c;
          r = x.r;
          e = x.e;
        }
        d = e + dp + 1;
        i = xc[d];
        k = baseOut / 2;
        r = r || d < 0 || xc[d + 1] != null;
        r = rm < 4 ? (i != null || r) && (rm == 0 || rm == (x.s < 0 ? 3 : 2)) : i > k || i == k && (rm == 4 || r || rm == 6 && xc[d - 1] & 1 || rm == (x.s < 0 ? 8 : 7));
        if (d < 1 || !xc[0]) {
          str = r ? toFixedPoint(alphabet.charAt(1), -dp, alphabet.charAt(0)) : alphabet.charAt(0);
        } else {
          xc.length = d;
          if (r) {
            for (--baseOut; ++xc[--d] > baseOut; ) {
              xc[d] = 0;
              if (!d) {
                ++e;
                xc = [1].concat(xc);
              }
            }
          }
          for (k = xc.length; !xc[--k]; ) ;
          for (i = 0, str = ""; i <= k; str += alphabet.charAt(xc[i++])) ;
          str = toFixedPoint(str, e, alphabet.charAt(0));
        }
        return str;
      };
    })();
    div = /* @__PURE__ */ (function() {
      function multiply(x, k, base) {
        var m, temp, xlo, xhi, carry = 0, i = x.length, klo = k % SQRT_BASE, khi = k / SQRT_BASE | 0;
        for (x = x.slice(); i--; ) {
          xlo = x[i] % SQRT_BASE;
          xhi = x[i] / SQRT_BASE | 0;
          m = khi * xlo + xhi * klo;
          temp = klo * xlo + m % SQRT_BASE * SQRT_BASE + carry;
          carry = (temp / base | 0) + (m / SQRT_BASE | 0) + khi * xhi;
          x[i] = temp % base;
        }
        if (carry) x = [carry].concat(x);
        return x;
      }
      function compare3(a, b, aL, bL) {
        var i, cmp;
        if (aL != bL) {
          cmp = aL > bL ? 1 : -1;
        } else {
          for (i = cmp = 0; i < aL; i++) {
            if (a[i] != b[i]) {
              cmp = a[i] > b[i] ? 1 : -1;
              break;
            }
          }
        }
        return cmp;
      }
      function subtract(a, b, aL, base) {
        var i = 0;
        for (; aL--; ) {
          a[aL] -= i;
          i = a[aL] < b[aL] ? 1 : 0;
          a[aL] = i * base + a[aL] - b[aL];
        }
        for (; !a[0] && a.length > 1; a.splice(0, 1)) ;
      }
      return function(x, y, dp, rm, base) {
        var cmp, e, i, more, n, prod, prodL, q, qc, rem, remL, rem0, xi, xL, yc0, yL, yz, s = x.s == y.s ? 1 : -1, xc = x.c, yc = y.c;
        if (!xc || !xc[0] || !yc || !yc[0]) {
          return new BigNumber2(
            // Return NaN if either NaN, or both Infinity or 0.
            !x.s || !y.s || (xc ? yc && xc[0] == yc[0] : !yc) ? NaN : (
              // Return ±0 if x is ±0 or y is ±Infinity, or return ±Infinity as y is ±0.
              xc && xc[0] == 0 || !yc ? s * 0 : s / 0
            )
          );
        }
        q = new BigNumber2(s);
        qc = q.c = [];
        e = x.e - y.e;
        s = dp + e + 1;
        if (!base) {
          base = BASE;
          e = bitFloor(x.e / LOG_BASE) - bitFloor(y.e / LOG_BASE);
          s = s / LOG_BASE | 0;
        }
        for (i = 0; yc[i] == (xc[i] || 0); i++) ;
        if (yc[i] > (xc[i] || 0)) e--;
        if (s < 0) {
          qc.push(1);
          more = true;
        } else {
          xL = xc.length;
          yL = yc.length;
          i = 0;
          s += 2;
          n = mathfloor(base / (yc[0] + 1));
          if (n > 1) {
            yc = multiply(yc, n, base);
            xc = multiply(xc, n, base);
            yL = yc.length;
            xL = xc.length;
          }
          xi = yL;
          rem = xc.slice(0, yL);
          remL = rem.length;
          for (; remL < yL; rem[remL++] = 0) ;
          yz = yc.slice();
          yz = [0].concat(yz);
          yc0 = yc[0];
          if (yc[1] >= base / 2) yc0++;
          do {
            n = 0;
            cmp = compare3(yc, rem, yL, remL);
            if (cmp < 0) {
              rem0 = rem[0];
              if (yL != remL) rem0 = rem0 * base + (rem[1] || 0);
              n = mathfloor(rem0 / yc0);
              if (n > 1) {
                if (n >= base) n = base - 1;
                prod = multiply(yc, n, base);
                prodL = prod.length;
                remL = rem.length;
                while (compare3(prod, rem, prodL, remL) == 1) {
                  n--;
                  subtract(prod, yL < prodL ? yz : yc, prodL, base);
                  prodL = prod.length;
                  cmp = 1;
                }
              } else {
                if (n == 0) {
                  cmp = n = 1;
                }
                prod = yc.slice();
                prodL = prod.length;
              }
              if (prodL < remL) prod = [0].concat(prod);
              subtract(rem, prod, remL, base);
              remL = rem.length;
              if (cmp == -1) {
                while (compare3(yc, rem, yL, remL) < 1) {
                  n++;
                  subtract(rem, yL < remL ? yz : yc, remL, base);
                  remL = rem.length;
                }
              }
            } else if (cmp === 0) {
              n++;
              rem = [0];
            }
            qc[i++] = n;
            if (rem[0]) {
              rem[remL++] = xc[xi] || 0;
            } else {
              rem = [xc[xi]];
              remL = 1;
            }
          } while ((xi++ < xL || rem[0] != null) && s--);
          more = rem[0] != null;
          if (!qc[0]) qc.splice(0, 1);
        }
        if (base == BASE) {
          for (i = 1, s = qc[0]; s >= 10; s /= 10, i++) ;
          round(q, dp + (q.e = i + e * LOG_BASE - 1) + 1, rm, more);
        } else {
          q.e = e;
          q.r = +more;
        }
        return q;
      };
    })();
    function format(n, i, rm, id) {
      var c0, e, ne, len, str;
      if (rm == null) rm = ROUNDING_MODE;
      else intCheck(rm, 0, 8);
      if (!n.c) return n.toString();
      c0 = n.c[0];
      ne = n.e;
      if (i == null) {
        str = coeffToString(n.c);
        str = id == 1 || id == 2 && (ne <= TO_EXP_NEG || ne >= TO_EXP_POS) ? toExponential(str, ne) : toFixedPoint(str, ne, "0");
      } else {
        n = round(new BigNumber2(n), i, rm);
        e = n.e;
        str = coeffToString(n.c);
        len = str.length;
        if (id == 1 || id == 2 && (i <= e || e <= TO_EXP_NEG)) {
          for (; len < i; str += "0", len++) ;
          str = toExponential(str, e);
        } else {
          i -= ne + (id === 2 && e > ne);
          str = toFixedPoint(str, e, "0");
          if (e + 1 > len) {
            if (--i > 0) for (str += "."; i--; str += "0") ;
          } else {
            i += e - len;
            if (i > 0) {
              if (e + 1 == len) str += ".";
              for (; i--; str += "0") ;
            }
          }
        }
      }
      return n.s < 0 && c0 ? "-" + str : str;
    }
    function maxOrMin(args, n) {
      var k, y, i = 1, x = new BigNumber2(args[0]);
      for (; i < args.length; i++) {
        y = new BigNumber2(args[i]);
        if (!y.s || (k = compare2(x, y)) === n || k === 0 && x.s === n) {
          x = y;
        }
      }
      return x;
    }
    function normalise(n, c, e) {
      var i = 1, j = c.length;
      for (; !c[--j]; c.pop()) ;
      for (j = c[0]; j >= 10; j /= 10, i++) ;
      if ((e = i + e * LOG_BASE - 1) > MAX_EXP) {
        n.c = n.e = null;
      } else if (e < MIN_EXP) {
        n.c = [n.e = 0];
      } else {
        n.e = e;
        n.c = c;
      }
      return n;
    }
    parseNumeric = /* @__PURE__ */ (function() {
      var basePrefix = /^(-?)0([xbo])(?=\w[\w.]*$)/i, dotAfter = /^([^.]+)\.$/, dotBefore = /^\.([^.]+)$/, isInfinityOrNaN = /^-?(Infinity|NaN)$/, whitespaceOrPlus = /^\s*\+(?=[\w.])|^\s+|\s+$/g;
      return function(x, str, isNum, b) {
        var base, s = isNum ? str : str.replace(whitespaceOrPlus, "");
        if (isInfinityOrNaN.test(s)) {
          x.s = isNaN(s) ? null : s < 0 ? -1 : 1;
        } else {
          if (!isNum) {
            s = s.replace(basePrefix, function(m, p1, p2) {
              base = (p2 = p2.toLowerCase()) == "x" ? 16 : p2 == "b" ? 2 : 8;
              return !b || b == base ? p1 : m;
            });
            if (b) {
              base = b;
              s = s.replace(dotAfter, "$1").replace(dotBefore, "0.$1");
            }
            if (str != s) return new BigNumber2(s, base);
          }
          if (BigNumber2.DEBUG) {
            throw Error(bignumberError + "Not a" + (b ? " base " + b : "") + " number: " + str);
          }
          x.s = null;
        }
        x.c = x.e = null;
      };
    })();
    function round(x, sd, rm, r) {
      var d, i, j, k, n, ni, rd, xc = x.c, pows10 = POWS_TEN;
      if (xc) {
        out: {
          for (d = 1, k = xc[0]; k >= 10; k /= 10, d++) ;
          i = sd - d;
          if (i < 0) {
            i += LOG_BASE;
            j = sd;
            n = xc[ni = 0];
            rd = mathfloor(n / pows10[d - j - 1] % 10);
          } else {
            ni = mathceil((i + 1) / LOG_BASE);
            if (ni >= xc.length) {
              if (r) {
                for (; xc.length <= ni; xc.push(0)) ;
                n = rd = 0;
                d = 1;
                i %= LOG_BASE;
                j = i - LOG_BASE + 1;
              } else {
                break out;
              }
            } else {
              n = k = xc[ni];
              for (d = 1; k >= 10; k /= 10, d++) ;
              i %= LOG_BASE;
              j = i - LOG_BASE + d;
              rd = j < 0 ? 0 : mathfloor(n / pows10[d - j - 1] % 10);
            }
          }
          r = r || sd < 0 || // Are there any non-zero digits after the rounding digit?
          // The expression  n % pows10[d - j - 1]  returns all digits of n to the right
          // of the digit at j, e.g. if n is 908714 and j is 2, the expression gives 714.
          xc[ni + 1] != null || (j < 0 ? n : n % pows10[d - j - 1]);
          r = rm < 4 ? (rd || r) && (rm == 0 || rm == (x.s < 0 ? 3 : 2)) : rd > 5 || rd == 5 && (rm == 4 || r || rm == 6 && // Check whether the digit to the left of the rounding digit is odd.
          (i > 0 ? j > 0 ? n / pows10[d - j] : 0 : xc[ni - 1]) % 10 & 1 || rm == (x.s < 0 ? 8 : 7));
          if (sd < 1 || !xc[0]) {
            xc.length = 0;
            if (r) {
              sd -= x.e + 1;
              xc[0] = pows10[(LOG_BASE - sd % LOG_BASE) % LOG_BASE];
              x.e = -sd || 0;
            } else {
              xc[0] = x.e = 0;
            }
            return x;
          }
          if (i == 0) {
            xc.length = ni;
            k = 1;
            ni--;
          } else {
            xc.length = ni + 1;
            k = pows10[LOG_BASE - i];
            xc[ni] = j > 0 ? mathfloor(n / pows10[d - j] % pows10[j]) * k : 0;
          }
          if (r) {
            for (; ; ) {
              if (ni == 0) {
                for (i = 1, j = xc[0]; j >= 10; j /= 10, i++) ;
                j = xc[0] += k;
                for (k = 1; j >= 10; j /= 10, k++) ;
                if (i != k) {
                  x.e++;
                  if (xc[0] == BASE) xc[0] = 1;
                }
                break;
              } else {
                xc[ni] += k;
                if (xc[ni] != BASE) break;
                xc[ni--] = 0;
                k = 1;
              }
            }
          }
          for (i = xc.length; xc[--i] === 0; xc.pop()) ;
        }
        if (x.e > MAX_EXP) {
          x.c = x.e = null;
        } else if (x.e < MIN_EXP) {
          x.c = [x.e = 0];
        }
      }
      return x;
    }
    function valueOf(n) {
      var str, e = n.e;
      if (e === null) return n.toString();
      str = coeffToString(n.c);
      str = e <= TO_EXP_NEG || e >= TO_EXP_POS ? toExponential(str, e) : toFixedPoint(str, e, "0");
      return n.s < 0 ? "-" + str : str;
    }
    P.absoluteValue = P.abs = function() {
      var x = new BigNumber2(this);
      if (x.s < 0) x.s = 1;
      return x;
    };
    P.comparedTo = function(y, b) {
      return compare2(this, new BigNumber2(y, b));
    };
    P.decimalPlaces = P.dp = function(dp, rm) {
      var c, n, v, x = this;
      if (dp != null) {
        intCheck(dp, 0, MAX);
        if (rm == null) rm = ROUNDING_MODE;
        else intCheck(rm, 0, 8);
        return round(new BigNumber2(x), dp + x.e + 1, rm);
      }
      if (!(c = x.c)) return null;
      n = ((v = c.length - 1) - bitFloor(this.e / LOG_BASE)) * LOG_BASE;
      if (v = c[v]) for (; v % 10 == 0; v /= 10, n--) ;
      if (n < 0) n = 0;
      return n;
    };
    P.dividedBy = P.div = function(y, b) {
      return div(this, new BigNumber2(y, b), DECIMAL_PLACES, ROUNDING_MODE);
    };
    P.dividedToIntegerBy = P.idiv = function(y, b) {
      return div(this, new BigNumber2(y, b), 0, 1);
    };
    P.exponentiatedBy = P.pow = function(n, m) {
      var half, isModExp, i, k, more, nIsBig, nIsNeg, nIsOdd, y, x = this;
      n = new BigNumber2(n);
      if (n.c && !n.isInteger()) {
        throw Error(bignumberError + "Exponent not an integer: " + valueOf(n));
      }
      if (m != null) m = new BigNumber2(m);
      nIsBig = n.e > 14;
      if (!x.c || !x.c[0] || x.c[0] == 1 && !x.e && x.c.length == 1 || !n.c || !n.c[0]) {
        y = new BigNumber2(Math.pow(+valueOf(x), nIsBig ? n.s * (2 - isOdd(n)) : +valueOf(n)));
        return m ? y.mod(m) : y;
      }
      nIsNeg = n.s < 0;
      if (m) {
        if (m.c ? !m.c[0] : !m.s) return new BigNumber2(NaN);
        isModExp = !nIsNeg && x.isInteger() && m.isInteger();
        if (isModExp) x = x.mod(m);
      } else if (n.e > 9 && (x.e > 0 || x.e < -1 || (x.e == 0 ? x.c[0] > 1 || nIsBig && x.c[1] >= 24e7 : x.c[0] < 8e13 || nIsBig && x.c[0] <= 9999975e7))) {
        k = x.s < 0 && isOdd(n) ? -0 : 0;
        if (x.e > -1) k = 1 / k;
        return new BigNumber2(nIsNeg ? 1 / k : k);
      } else if (POW_PRECISION) {
        k = mathceil(POW_PRECISION / LOG_BASE + 2);
      }
      if (nIsBig) {
        half = new BigNumber2(0.5);
        if (nIsNeg) n.s = 1;
        nIsOdd = isOdd(n);
      } else {
        i = Math.abs(+valueOf(n));
        nIsOdd = i % 2;
      }
      y = new BigNumber2(ONE);
      for (; ; ) {
        if (nIsOdd) {
          y = y.times(x);
          if (!y.c) break;
          if (k) {
            if (y.c.length > k) y.c.length = k;
          } else if (isModExp) {
            y = y.mod(m);
          }
        }
        if (i) {
          i = mathfloor(i / 2);
          if (i === 0) break;
          nIsOdd = i % 2;
        } else {
          n = n.times(half);
          round(n, n.e + 1, 1);
          if (n.e > 14) {
            nIsOdd = isOdd(n);
          } else {
            i = +valueOf(n);
            if (i === 0) break;
            nIsOdd = i % 2;
          }
        }
        x = x.times(x);
        if (k) {
          if (x.c && x.c.length > k) x.c.length = k;
        } else if (isModExp) {
          x = x.mod(m);
        }
      }
      if (isModExp) return y;
      if (nIsNeg) y = ONE.div(y);
      return m ? y.mod(m) : k ? round(y, POW_PRECISION, ROUNDING_MODE, more) : y;
    };
    P.integerValue = function(rm) {
      var n = new BigNumber2(this);
      if (rm == null) rm = ROUNDING_MODE;
      else intCheck(rm, 0, 8);
      return round(n, n.e + 1, rm);
    };
    P.isEqualTo = P.eq = function(y, b) {
      return compare2(this, new BigNumber2(y, b)) === 0;
    };
    P.isFinite = function() {
      return !!this.c;
    };
    P.isGreaterThan = P.gt = function(y, b) {
      return compare2(this, new BigNumber2(y, b)) > 0;
    };
    P.isGreaterThanOrEqualTo = P.gte = function(y, b) {
      return (b = compare2(this, new BigNumber2(y, b))) === 1 || b === 0;
    };
    P.isInteger = function() {
      return !!this.c && bitFloor(this.e / LOG_BASE) > this.c.length - 2;
    };
    P.isLessThan = P.lt = function(y, b) {
      return compare2(this, new BigNumber2(y, b)) < 0;
    };
    P.isLessThanOrEqualTo = P.lte = function(y, b) {
      return (b = compare2(this, new BigNumber2(y, b))) === -1 || b === 0;
    };
    P.isNaN = function() {
      return !this.s;
    };
    P.isNegative = function() {
      return this.s < 0;
    };
    P.isPositive = function() {
      return this.s > 0;
    };
    P.isZero = function() {
      return !!this.c && this.c[0] == 0;
    };
    P.minus = function(y, b) {
      var i, j, t, xLTy, x = this, a = x.s;
      y = new BigNumber2(y, b);
      b = y.s;
      if (!a || !b) return new BigNumber2(NaN);
      if (a != b) {
        y.s = -b;
        return x.plus(y);
      }
      var xe = x.e / LOG_BASE, ye = y.e / LOG_BASE, xc = x.c, yc = y.c;
      if (!xe || !ye) {
        if (!xc || !yc) return xc ? (y.s = -b, y) : new BigNumber2(yc ? x : NaN);
        if (!xc[0] || !yc[0]) {
          return yc[0] ? (y.s = -b, y) : new BigNumber2(xc[0] ? x : (
            // IEEE 754 (2008) 6.3: n - n = -0 when rounding to -Infinity
            ROUNDING_MODE == 3 ? -0 : 0
          ));
        }
      }
      xe = bitFloor(xe);
      ye = bitFloor(ye);
      xc = xc.slice();
      if (a = xe - ye) {
        if (xLTy = a < 0) {
          a = -a;
          t = xc;
        } else {
          ye = xe;
          t = yc;
        }
        t.reverse();
        for (b = a; b--; t.push(0)) ;
        t.reverse();
      } else {
        j = (xLTy = (a = xc.length) < (b = yc.length)) ? a : b;
        for (a = b = 0; b < j; b++) {
          if (xc[b] != yc[b]) {
            xLTy = xc[b] < yc[b];
            break;
          }
        }
      }
      if (xLTy) {
        t = xc;
        xc = yc;
        yc = t;
        y.s = -y.s;
      }
      b = (j = yc.length) - (i = xc.length);
      if (b > 0) for (; b--; xc[i++] = 0) ;
      b = BASE - 1;
      for (; j > a; ) {
        if (xc[--j] < yc[j]) {
          for (i = j; i && !xc[--i]; xc[i] = b) ;
          --xc[i];
          xc[j] += BASE;
        }
        xc[j] -= yc[j];
      }
      for (; xc[0] == 0; xc.splice(0, 1), --ye) ;
      if (!xc[0]) {
        y.s = ROUNDING_MODE == 3 ? -1 : 1;
        y.c = [y.e = 0];
        return y;
      }
      return normalise(y, xc, ye);
    };
    P.modulo = P.mod = function(y, b) {
      var q, s, x = this;
      y = new BigNumber2(y, b);
      if (!x.c || !y.s || y.c && !y.c[0]) {
        return new BigNumber2(NaN);
      } else if (!y.c || x.c && !x.c[0]) {
        return new BigNumber2(x);
      }
      if (MODULO_MODE == 9) {
        s = y.s;
        y.s = 1;
        q = div(x, y, 0, 3);
        y.s = s;
        q.s *= s;
      } else {
        q = div(x, y, 0, MODULO_MODE);
      }
      y = x.minus(q.times(y));
      if (!y.c[0] && MODULO_MODE == 1) y.s = x.s;
      return y;
    };
    P.multipliedBy = P.times = function(y, b) {
      var c, e, i, j, k, m, xcL, xlo, xhi, ycL, ylo, yhi, zc, base, sqrtBase, x = this, xc = x.c, yc = (y = new BigNumber2(y, b)).c;
      if (!xc || !yc || !xc[0] || !yc[0]) {
        if (!x.s || !y.s || xc && !xc[0] && !yc || yc && !yc[0] && !xc) {
          y.c = y.e = y.s = null;
        } else {
          y.s *= x.s;
          if (!xc || !yc) {
            y.c = y.e = null;
          } else {
            y.c = [0];
            y.e = 0;
          }
        }
        return y;
      }
      e = bitFloor(x.e / LOG_BASE) + bitFloor(y.e / LOG_BASE);
      y.s *= x.s;
      xcL = xc.length;
      ycL = yc.length;
      if (xcL < ycL) {
        zc = xc;
        xc = yc;
        yc = zc;
        i = xcL;
        xcL = ycL;
        ycL = i;
      }
      for (i = xcL + ycL, zc = []; i--; zc.push(0)) ;
      base = BASE;
      sqrtBase = SQRT_BASE;
      for (i = ycL; --i >= 0; ) {
        c = 0;
        ylo = yc[i] % sqrtBase;
        yhi = yc[i] / sqrtBase | 0;
        for (k = xcL, j = i + k; j > i; ) {
          xlo = xc[--k] % sqrtBase;
          xhi = xc[k] / sqrtBase | 0;
          m = yhi * xlo + xhi * ylo;
          xlo = ylo * xlo + m % sqrtBase * sqrtBase + zc[j] + c;
          c = (xlo / base | 0) + (m / sqrtBase | 0) + yhi * xhi;
          zc[j--] = xlo % base;
        }
        zc[j] = c;
      }
      if (c) {
        ++e;
      } else {
        zc.splice(0, 1);
      }
      return normalise(y, zc, e);
    };
    P.negated = function() {
      var x = new BigNumber2(this);
      x.s = -x.s || null;
      return x;
    };
    P.plus = function(y, b) {
      var t, x = this, a = x.s;
      y = new BigNumber2(y, b);
      b = y.s;
      if (!a || !b) return new BigNumber2(NaN);
      if (a != b) {
        y.s = -b;
        return x.minus(y);
      }
      var xe = x.e / LOG_BASE, ye = y.e / LOG_BASE, xc = x.c, yc = y.c;
      if (!xe || !ye) {
        if (!xc || !yc) return new BigNumber2(a / 0);
        if (!xc[0] || !yc[0]) return yc[0] ? y : new BigNumber2(xc[0] ? x : a * 0);
      }
      xe = bitFloor(xe);
      ye = bitFloor(ye);
      xc = xc.slice();
      if (a = xe - ye) {
        if (a > 0) {
          ye = xe;
          t = yc;
        } else {
          a = -a;
          t = xc;
        }
        t.reverse();
        for (; a--; t.push(0)) ;
        t.reverse();
      }
      a = xc.length;
      b = yc.length;
      if (a - b < 0) {
        t = yc;
        yc = xc;
        xc = t;
        b = a;
      }
      for (a = 0; b; ) {
        a = (xc[--b] = xc[b] + yc[b] + a) / BASE | 0;
        xc[b] = BASE === xc[b] ? 0 : xc[b] % BASE;
      }
      if (a) {
        xc = [a].concat(xc);
        ++ye;
      }
      return normalise(y, xc, ye);
    };
    P.precision = P.sd = function(sd, rm) {
      var c, n, v, x = this;
      if (sd != null && sd !== !!sd) {
        intCheck(sd, 1, MAX);
        if (rm == null) rm = ROUNDING_MODE;
        else intCheck(rm, 0, 8);
        return round(new BigNumber2(x), sd, rm);
      }
      if (!(c = x.c)) return null;
      v = c.length - 1;
      n = v * LOG_BASE + 1;
      if (v = c[v]) {
        for (; v % 10 == 0; v /= 10, n--) ;
        for (v = c[0]; v >= 10; v /= 10, n++) ;
      }
      if (sd && x.e + 1 > n) n = x.e + 1;
      return n;
    };
    P.shiftedBy = function(k) {
      intCheck(k, -MAX_SAFE_INTEGER, MAX_SAFE_INTEGER);
      return this.times("1e" + k);
    };
    P.squareRoot = P.sqrt = function() {
      var m, n, r, rep, t, x = this, c = x.c, s = x.s, e = x.e, dp = DECIMAL_PLACES + 4, half = new BigNumber2("0.5");
      if (s !== 1 || !c || !c[0]) {
        return new BigNumber2(!s || s < 0 && (!c || c[0]) ? NaN : c ? x : 1 / 0);
      }
      s = Math.sqrt(+valueOf(x));
      if (s == 0 || s == 1 / 0) {
        n = coeffToString(c);
        if ((n.length + e) % 2 == 0) n += "0";
        s = Math.sqrt(+n);
        e = bitFloor((e + 1) / 2) - (e < 0 || e % 2);
        if (s == 1 / 0) {
          n = "5e" + e;
        } else {
          n = s.toExponential();
          n = n.slice(0, n.indexOf("e") + 1) + e;
        }
        r = new BigNumber2(n);
      } else {
        r = new BigNumber2(s + "");
      }
      if (r.c[0]) {
        e = r.e;
        s = e + dp;
        if (s < 3) s = 0;
        for (; ; ) {
          t = r;
          r = half.times(t.plus(div(x, t, dp, 1)));
          if (coeffToString(t.c).slice(0, s) === (n = coeffToString(r.c)).slice(0, s)) {
            if (r.e < e) --s;
            n = n.slice(s - 3, s + 1);
            if (n == "9999" || !rep && n == "4999") {
              if (!rep) {
                round(t, t.e + DECIMAL_PLACES + 2, 0);
                if (t.times(t).eq(x)) {
                  r = t;
                  break;
                }
              }
              dp += 4;
              s += 4;
              rep = 1;
            } else {
              if (!+n || !+n.slice(1) && n.charAt(0) == "5") {
                round(r, r.e + DECIMAL_PLACES + 2, 1);
                m = !r.times(r).eq(x);
              }
              break;
            }
          }
        }
      }
      return round(r, r.e + DECIMAL_PLACES + 1, ROUNDING_MODE, m);
    };
    P.toExponential = function(dp, rm) {
      if (dp != null) {
        intCheck(dp, 0, MAX);
        dp++;
      }
      return format(this, dp, rm, 1);
    };
    P.toFixed = function(dp, rm) {
      if (dp != null) {
        intCheck(dp, 0, MAX);
        dp = dp + this.e + 1;
      }
      return format(this, dp, rm);
    };
    P.toFormat = function(dp, rm, format2) {
      var str, x = this;
      if (format2 == null) {
        if (dp != null && rm && typeof rm == "object") {
          format2 = rm;
          rm = null;
        } else if (dp && typeof dp == "object") {
          format2 = dp;
          dp = rm = null;
        } else {
          format2 = FORMAT;
        }
      } else if (typeof format2 != "object") {
        throw Error(bignumberError + "Argument not an object: " + format2);
      }
      str = x.toFixed(dp, rm);
      if (x.c) {
        var i, arr = str.split("."), g1 = +format2.groupSize, g2 = +format2.secondaryGroupSize, groupSeparator = format2.groupSeparator || "", intPart = arr[0], fractionPart = arr[1], isNeg = x.s < 0, intDigits = isNeg ? intPart.slice(1) : intPart, len = intDigits.length;
        if (g2) {
          i = g1;
          g1 = g2;
          g2 = i;
          len -= i;
        }
        if (g1 > 0 && len > 0) {
          i = len % g1 || g1;
          intPart = intDigits.substr(0, i);
          for (; i < len; i += g1) intPart += groupSeparator + intDigits.substr(i, g1);
          if (g2 > 0) intPart += groupSeparator + intDigits.slice(i);
          if (isNeg) intPart = "-" + intPart;
        }
        str = fractionPart ? intPart + (format2.decimalSeparator || "") + ((g2 = +format2.fractionGroupSize) ? fractionPart.replace(
          new RegExp("\\d{" + g2 + "}\\B", "g"),
          "$&" + (format2.fractionGroupSeparator || "")
        ) : fractionPart) : intPart;
      }
      return (format2.prefix || "") + str + (format2.suffix || "");
    };
    P.toFraction = function(md) {
      var d, d0, d1, d2, e, exp, n, n0, n1, q, r, s, x = this, xc = x.c;
      if (md != null) {
        n = new BigNumber2(md);
        if (!n.isInteger() && (n.c || n.s !== 1) || n.lt(ONE)) {
          throw Error(bignumberError + "Argument " + (n.isInteger() ? "out of range: " : "not an integer: ") + valueOf(n));
        }
      }
      if (!xc) return new BigNumber2(x);
      d = new BigNumber2(ONE);
      n1 = d0 = new BigNumber2(ONE);
      d1 = n0 = new BigNumber2(ONE);
      s = coeffToString(xc);
      e = d.e = s.length - x.e - 1;
      d.c[0] = POWS_TEN[(exp = e % LOG_BASE) < 0 ? LOG_BASE + exp : exp];
      md = !md || n.comparedTo(d) > 0 ? e > 0 ? d : n1 : n;
      exp = MAX_EXP;
      MAX_EXP = 1 / 0;
      n = new BigNumber2(s);
      n0.c[0] = 0;
      for (; ; ) {
        q = div(n, d, 0, 1);
        d2 = d0.plus(q.times(d1));
        if (d2.comparedTo(md) == 1) break;
        d0 = d1;
        d1 = d2;
        n1 = n0.plus(q.times(d2 = n1));
        n0 = d2;
        d = n.minus(q.times(d2 = d));
        n = d2;
      }
      d2 = div(md.minus(d0), d1, 0, 1);
      n0 = n0.plus(d2.times(n1));
      d0 = d0.plus(d2.times(d1));
      n0.s = n1.s = x.s;
      e = e * 2;
      r = div(n1, d1, e, ROUNDING_MODE).minus(x).abs().comparedTo(
        div(n0, d0, e, ROUNDING_MODE).minus(x).abs()
      ) < 1 ? [n1, d1] : [n0, d0];
      MAX_EXP = exp;
      return r;
    };
    P.toNumber = function() {
      return +valueOf(this);
    };
    P.toPrecision = function(sd, rm) {
      if (sd != null) intCheck(sd, 1, MAX);
      return format(this, sd, rm, 2);
    };
    P.toString = function(b) {
      var str, n = this, s = n.s, e = n.e;
      if (e === null) {
        if (s) {
          str = "Infinity";
          if (s < 0) str = "-" + str;
        } else {
          str = "NaN";
        }
      } else {
        if (b == null) {
          str = e <= TO_EXP_NEG || e >= TO_EXP_POS ? toExponential(coeffToString(n.c), e) : toFixedPoint(coeffToString(n.c), e, "0");
        } else if (b === 10 && alphabetHasNormalDecimalDigits) {
          n = round(new BigNumber2(n), DECIMAL_PLACES + e + 1, ROUNDING_MODE);
          str = toFixedPoint(coeffToString(n.c), n.e, "0");
        } else {
          intCheck(b, 2, ALPHABET.length, "Base");
          str = convertBase(toFixedPoint(coeffToString(n.c), e, "0"), 10, b, s, true);
        }
        if (s < 0 && n.c[0]) str = "-" + str;
      }
      return str;
    };
    P.valueOf = P.toJSON = function() {
      return valueOf(this);
    };
    P._isBigNumber = true;
    P[Symbol.toStringTag] = "BigNumber";
    P[Symbol.for("nodejs.util.inspect.custom")] = P.valueOf;
    if (configObject != null) BigNumber2.set(configObject);
    return BigNumber2;
  }
  function bitFloor(n) {
    var i = n | 0;
    return n > 0 || n === i ? i : i - 1;
  }
  function coeffToString(a) {
    var s, z, i = 1, j = a.length, r = a[0] + "";
    for (; i < j; ) {
      s = a[i++] + "";
      z = LOG_BASE - s.length;
      for (; z--; s = "0" + s) ;
      r += s;
    }
    for (j = r.length; r.charCodeAt(--j) === 48; ) ;
    return r.slice(0, j + 1 || 1);
  }
  function compare2(x, y) {
    var a, b, xc = x.c, yc = y.c, i = x.s, j = y.s, k = x.e, l = y.e;
    if (!i || !j) return null;
    a = xc && !xc[0];
    b = yc && !yc[0];
    if (a || b) return a ? b ? 0 : -j : i;
    if (i != j) return i;
    a = i < 0;
    b = k == l;
    if (!xc || !yc) return b ? 0 : !xc ^ a ? 1 : -1;
    if (!b) return k > l ^ a ? 1 : -1;
    j = (k = xc.length) < (l = yc.length) ? k : l;
    for (i = 0; i < j; i++) if (xc[i] != yc[i]) return xc[i] > yc[i] ^ a ? 1 : -1;
    return k == l ? 0 : k > l ^ a ? 1 : -1;
  }
  function intCheck(n, min, max, name) {
    if (n < min || n > max || n !== mathfloor(n)) {
      throw Error(bignumberError + (name || "Argument") + (typeof n == "number" ? n < min || n > max ? " out of range: " : " not an integer: " : " not a primitive number: ") + String(n));
    }
  }
  function isOdd(n) {
    var k = n.c.length - 1;
    return bitFloor(n.e / LOG_BASE) == k && n.c[k] % 2 != 0;
  }
  function toExponential(str, e) {
    return (str.length > 1 ? str.charAt(0) + "." + str.slice(1) : str) + (e < 0 ? "e" : "e+") + e;
  }
  function toFixedPoint(str, e, z) {
    var len, zs;
    if (e < 0) {
      for (zs = z + "."; ++e; zs += z) ;
      str = zs + str;
    } else {
      len = str.length;
      if (++e > len) {
        for (zs = z, e -= len; --e; zs += z) ;
        str += zs;
      } else if (e < len) {
        str = str.slice(0, e) + "." + str.slice(e);
      }
    }
    return str;
  }
  var isNumeric, mathceil, mathfloor, bignumberError, tooManyDigits, BASE, LOG_BASE, MAX_SAFE_INTEGER, POWS_TEN, SQRT_BASE, MAX, BigNumber, bignumber_default;
  var init_bignumber = __esm({
    "node_modules/bignumber.js/bignumber.mjs"() {
      init_buffer_shim();
      isNumeric = /^-?(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?$/i;
      mathceil = Math.ceil;
      mathfloor = Math.floor;
      bignumberError = "[BigNumber Error] ";
      tooManyDigits = bignumberError + "Number primitive has more than 15 significant digits: ";
      BASE = 1e14;
      LOG_BASE = 14;
      MAX_SAFE_INTEGER = 9007199254740991;
      POWS_TEN = [1, 10, 100, 1e3, 1e4, 1e5, 1e6, 1e7, 1e8, 1e9, 1e10, 1e11, 1e12, 1e13];
      SQRT_BASE = 1e7;
      MAX = 1e9;
      BigNumber = clone();
      bignumber_default = BigNumber;
    }
  });

  // node_modules/jsbi/dist/jsbi-umd.js
  var require_jsbi_umd = __commonJS({
    "node_modules/jsbi/dist/jsbi-umd.js"(exports, module) {
      init_buffer_shim();
      (function(e, t) {
        "object" == typeof exports && "undefined" != typeof module ? module.exports = t() : "function" == typeof define && define.amd ? define(t) : (e = e || self, e.JSBI = t());
      })(exports, function() {
        "use strict";
        var e = Math.imul, t = Math.clz32;
        function i(e2) {
          "@babel/helpers - typeof";
          return i = "function" == typeof Symbol && "symbol" == typeof Symbol.iterator ? function(e3) {
            return typeof e3;
          } : function(e3) {
            return e3 && "function" == typeof Symbol && e3.constructor === Symbol && e3 !== Symbol.prototype ? "symbol" : typeof e3;
          }, i(e2);
        }
        function _(e2, t2) {
          if (!(e2 instanceof t2)) throw new TypeError("Cannot call a class as a function");
        }
        function n(e2, t2) {
          for (var _2, n2 = 0; n2 < t2.length; n2++) _2 = t2[n2], _2.enumerable = _2.enumerable || false, _2.configurable = true, "value" in _2 && (_2.writable = true), Object.defineProperty(e2, _2.key, _2);
        }
        function l(e2, t2, i2) {
          return t2 && n(e2.prototype, t2), i2 && n(e2, i2), e2;
        }
        function g(e2, t2) {
          if ("function" != typeof t2 && null !== t2) throw new TypeError("Super expression must either be null or a function");
          e2.prototype = Object.create(t2 && t2.prototype, { constructor: { value: e2, writable: true, configurable: true } }), t2 && u(e2, t2);
        }
        function a(e2) {
          return a = Object.setPrototypeOf ? Object.getPrototypeOf : function(e3) {
            return e3.__proto__ || Object.getPrototypeOf(e3);
          }, a(e2);
        }
        function u(e2, t2) {
          return u = Object.setPrototypeOf || function(e3, t3) {
            return e3.__proto__ = t3, e3;
          }, u(e2, t2);
        }
        function s() {
          if ("undefined" == typeof Reflect || !Reflect.construct) return false;
          if (Reflect.construct.sham) return false;
          if ("function" == typeof Proxy) return true;
          try {
            return Boolean.prototype.valueOf.call(Reflect.construct(Boolean, [], function() {
            })), true;
          } catch (t2) {
            return false;
          }
        }
        function r() {
          return r = s() ? Reflect.construct : function(e2, t2, i2) {
            var _2 = [null];
            _2.push.apply(_2, t2);
            var n2 = Function.bind.apply(e2, _2), l2 = new n2();
            return i2 && u(l2, i2.prototype), l2;
          }, r.apply(null, arguments);
        }
        function d(e2) {
          return -1 !== Function.toString.call(e2).indexOf("[native code]");
        }
        function h(e2) {
          var t2 = "function" == typeof Map ? /* @__PURE__ */ new Map() : void 0;
          return h = function(e3) {
            function i2() {
              return r(e3, arguments, a(this).constructor);
            }
            if (null === e3 || !d(e3)) return e3;
            if ("function" != typeof e3) throw new TypeError("Super expression must either be null or a function");
            if ("undefined" != typeof t2) {
              if (t2.has(e3)) return t2.get(e3);
              t2.set(e3, i2);
            }
            return i2.prototype = Object.create(e3.prototype, { constructor: { value: i2, enumerable: false, writable: true, configurable: true } }), u(i2, e3);
          }, h(e2);
        }
        function b(e2) {
          if (void 0 === e2) throw new ReferenceError("this hasn't been initialised - super() hasn't been called");
          return e2;
        }
        function m(e2, t2) {
          return t2 && ("object" == typeof t2 || "function" == typeof t2) ? t2 : b(e2);
        }
        function c(e2) {
          var t2 = s();
          return function() {
            var i2, _2 = a(e2);
            if (t2) {
              var n2 = a(this).constructor;
              i2 = Reflect.construct(_2, arguments, n2);
            } else i2 = _2.apply(this, arguments);
            return m(this, i2);
          };
        }
        function v(e2, t2) {
          if (e2) {
            if ("string" == typeof e2) return f(e2, t2);
            var i2 = Object.prototype.toString.call(e2).slice(8, -1);
            return "Object" === i2 && e2.constructor && (i2 = e2.constructor.name), "Map" === i2 || "Set" === i2 ? Array.from(e2) : "Arguments" === i2 || /^(?:Ui|I)nt(?:8|16|32)(?:Clamped)?Array$/.test(i2) ? f(e2, t2) : void 0;
          }
        }
        function f(e2, t2) {
          (null == t2 || t2 > e2.length) && (t2 = e2.length);
          for (var _2 = 0, n2 = Array(t2); _2 < t2; _2++) n2[_2] = e2[_2];
          return n2;
        }
        function y(e2, t2) {
          var _2 = "undefined" != typeof Symbol && e2[Symbol.iterator] || e2["@@iterator"];
          if (!_2) {
            if (Array.isArray(e2) || (_2 = v(e2)) || t2 && e2 && "number" == typeof e2.length) {
              _2 && (e2 = _2);
              var n2 = 0, l2 = function() {
              };
              return { s: l2, n: function() {
                return n2 >= e2.length ? { done: true } : { done: false, value: e2[n2++] };
              }, e: function(t3) {
                throw t3;
              }, f: l2 };
            }
            throw new TypeError("Invalid attempt to iterate non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method.");
          }
          var g2, a2 = true, u2 = false;
          return { s: function() {
            _2 = _2.call(e2);
          }, n: function() {
            var e3 = _2.next();
            return a2 = e3.done, e3;
          }, e: function(t3) {
            u2 = true, g2 = t3;
          }, f: function() {
            try {
              a2 || null == _2.return || _2.return();
            } finally {
              if (u2) throw g2;
            }
          } };
        }
        var k = (function(e2) {
          var t2 = Math.abs, n2 = Math.max;
          function o(e3, t3) {
            var i2;
            if (_(this, o), e3 > o.__kMaxLength) throw new RangeError("Maximum BigInt size exceeded");
            return i2 = a2.call(this, e3), i2.sign = t3, i2;
          }
          g(o, e2);
          var a2 = c(o);
          return l(o, [{ key: "toDebugString", value: function() {
            var e3, t3 = ["BigInt["], i2 = y(this);
            try {
              for (i2.s(); !(e3 = i2.n()).done; ) {
                var _2 = e3.value;
                t3.push((_2 ? (_2 >>> 0).toString(16) : _2) + ", ");
              }
            } catch (e4) {
              i2.e(e4);
            } finally {
              i2.f();
            }
            return t3.push("]"), t3.join("");
          } }, { key: "toString", value: function() {
            var e3 = 0 < arguments.length && void 0 !== arguments[0] ? arguments[0] : 10;
            if (2 > e3 || 36 < e3) throw new RangeError("toString() radix argument must be between 2 and 36");
            return 0 === this.length ? "0" : 0 == (e3 & e3 - 1) ? o.__toStringBasePowerOfTwo(this, e3) : o.__toStringGeneric(this, e3, false);
          } }, { key: "__copy", value: function() {
            for (var e3 = new o(this.length, this.sign), t3 = 0; t3 < this.length; t3++) e3[t3] = this[t3];
            return e3;
          } }, { key: "__trim", value: function() {
            for (var e3 = this.length, t3 = this[e3 - 1]; 0 === t3; ) e3--, t3 = this[e3 - 1], this.pop();
            return 0 === e3 && (this.sign = false), this;
          } }, { key: "__initializeDigits", value: function() {
            for (var e3 = 0; e3 < this.length; e3++) this[e3] = 0;
          } }, { key: "__clzmsd", value: function() {
            return o.__clz32(this[this.length - 1]);
          } }, { key: "__inplaceMultiplyAdd", value: function(e3, t3, _2) {
            _2 > this.length && (_2 = this.length);
            for (var n3 = 65535 & e3, l2 = e3 >>> 16, g2 = 0, a3 = 65535 & t3, u2 = t3 >>> 16, s2 = 0; s2 < _2; s2++) {
              var r2 = this.__digit(s2), d2 = 65535 & r2, h2 = r2 >>> 16, b2 = o.__imul(d2, n3), m2 = o.__imul(d2, l2), c2 = o.__imul(h2, n3), v2 = o.__imul(h2, l2), f2 = a3 + (65535 & b2), y2 = u2 + g2 + (f2 >>> 16) + (b2 >>> 16) + (65535 & m2) + (65535 & c2);
              a3 = (m2 >>> 16) + (c2 >>> 16) + (65535 & v2) + (y2 >>> 16), g2 = a3 >>> 16, a3 &= 65535, u2 = v2 >>> 16;
              this.__setDigit(s2, 65535 & f2 | y2 << 16);
            }
            if (0 !== g2 || 0 !== a3 || 0 !== u2) throw new Error("implementation bug");
          } }, { key: "__inplaceAdd", value: function(e3, t3, _2) {
            for (var n3, l2 = 0, g2 = 0; g2 < _2; g2++) n3 = this.__halfDigit(t3 + g2) + e3.__halfDigit(g2) + l2, l2 = n3 >>> 16, this.__setHalfDigit(t3 + g2, n3);
            return l2;
          } }, { key: "__inplaceSub", value: function(e3, t3, _2) {
            var n3 = 0;
            if (1 & t3) {
              t3 >>= 1;
              for (var l2 = this.__digit(t3), g2 = 65535 & l2, o2 = 0; o2 < _2 - 1 >>> 1; o2++) {
                var a3 = e3.__digit(o2), u2 = (l2 >>> 16) - (65535 & a3) - n3;
                n3 = 1 & u2 >>> 16, this.__setDigit(t3 + o2, u2 << 16 | 65535 & g2), l2 = this.__digit(t3 + o2 + 1), g2 = (65535 & l2) - (a3 >>> 16) - n3, n3 = 1 & g2 >>> 16;
              }
              var s2 = e3.__digit(o2), r2 = (l2 >>> 16) - (65535 & s2) - n3;
              n3 = 1 & r2 >>> 16, this.__setDigit(t3 + o2, r2 << 16 | 65535 & g2);
              if (t3 + o2 + 1 >= this.length) throw new RangeError("out of bounds");
              0 == (1 & _2) && (l2 = this.__digit(t3 + o2 + 1), g2 = (65535 & l2) - (s2 >>> 16) - n3, n3 = 1 & g2 >>> 16, this.__setDigit(t3 + e3.length, 4294901760 & l2 | 65535 & g2));
            } else {
              t3 >>= 1;
              for (var d2 = 0; d2 < e3.length - 1; d2++) {
                var h2 = this.__digit(t3 + d2), b2 = e3.__digit(d2), m2 = (65535 & h2) - (65535 & b2) - n3;
                n3 = 1 & m2 >>> 16;
                var c2 = (h2 >>> 16) - (b2 >>> 16) - n3;
                n3 = 1 & c2 >>> 16, this.__setDigit(t3 + d2, c2 << 16 | 65535 & m2);
              }
              var v2 = this.__digit(t3 + d2), f2 = e3.__digit(d2), y2 = (65535 & v2) - (65535 & f2) - n3;
              n3 = 1 & y2 >>> 16;
              var k2 = 0;
              0 == (1 & _2) && (k2 = (v2 >>> 16) - (f2 >>> 16) - n3, n3 = 1 & k2 >>> 16), this.__setDigit(t3 + d2, k2 << 16 | 65535 & y2);
            }
            return n3;
          } }, { key: "__inplaceRightShift", value: function(e3) {
            if (0 !== e3) {
              for (var t3, _2 = this.__digit(0) >>> e3, n3 = this.length - 1, l2 = 0; l2 < n3; l2++) t3 = this.__digit(l2 + 1), this.__setDigit(l2, t3 << 32 - e3 | _2), _2 = t3 >>> e3;
              this.__setDigit(n3, _2);
            }
          } }, { key: "__digit", value: function(e3) {
            return this[e3];
          } }, { key: "__unsignedDigit", value: function(e3) {
            return this[e3] >>> 0;
          } }, { key: "__setDigit", value: function(e3, t3) {
            this[e3] = 0 | t3;
          } }, { key: "__setDigitGrow", value: function(e3, t3) {
            this[e3] = 0 | t3;
          } }, { key: "__halfDigitLength", value: function() {
            var e3 = this.length;
            return 65535 >= this.__unsignedDigit(e3 - 1) ? 2 * e3 - 1 : 2 * e3;
          } }, { key: "__halfDigit", value: function(e3) {
            return 65535 & this[e3 >>> 1] >>> ((1 & e3) << 4);
          } }, { key: "__setHalfDigit", value: function(e3, t3) {
            var i2 = e3 >>> 1, _2 = this.__digit(i2), n3 = 1 & e3 ? 65535 & _2 | t3 << 16 : 4294901760 & _2 | 65535 & t3;
            this.__setDigit(i2, n3);
          } }], [{ key: "BigInt", value: function(e3) {
            var t3 = Math.floor, _2 = Number.isFinite;
            if ("number" == typeof e3) {
              if (0 === e3) return o.__zero();
              if ((0 | e3) === e3) return 0 > e3 ? o.__oneDigit(-e3, true) : o.__oneDigit(e3, false);
              if (!_2(e3) || t3(e3) !== e3) throw new RangeError("The number " + e3 + " cannot be converted to BigInt because it is not an integer");
              return o.__fromDouble(e3);
            }
            if ("string" == typeof e3) {
              var n3 = o.__fromString(e3);
              if (null === n3) throw new SyntaxError("Cannot convert " + e3 + " to a BigInt");
              return n3;
            }
            if ("boolean" == typeof e3) return true === e3 ? o.__oneDigit(1, false) : o.__zero();
            if ("object" === i(e3)) {
              if (e3.constructor === o) return e3;
              var l2 = o.__toPrimitive(e3);
              return o.BigInt(l2);
            }
            throw new TypeError("Cannot convert " + e3 + " to a BigInt");
          } }, { key: "toNumber", value: function(e3) {
            var t3 = e3.length;
            if (0 === t3) return 0;
            if (1 === t3) {
              var i2 = e3.__unsignedDigit(0);
              return e3.sign ? -i2 : i2;
            }
            var _2 = e3.__digit(t3 - 1), n3 = o.__clz32(_2), l2 = 32 * t3 - n3;
            if (1024 < l2) return e3.sign ? -Infinity : 1 / 0;
            var g2 = l2 - 1, a3 = _2, u2 = t3 - 1, s2 = n3 + 1, r2 = 32 === s2 ? 0 : a3 << s2;
            r2 >>>= 12;
            var d2 = s2 - 12, h2 = 12 <= s2 ? 0 : a3 << 20 + s2, b2 = 20 + s2;
            0 < d2 && 0 < u2 && (u2--, a3 = e3.__digit(u2), r2 |= a3 >>> 32 - d2, h2 = a3 << d2, b2 = d2), 0 < b2 && 0 < u2 && (u2--, a3 = e3.__digit(u2), h2 |= a3 >>> 32 - b2, b2 -= 32);
            var m2 = o.__decideRounding(e3, b2, u2, a3);
            if ((1 === m2 || 0 === m2 && 1 == (1 & h2)) && (h2 = h2 + 1 >>> 0, 0 === h2 && (r2++, 0 != r2 >>> 20 && (r2 = 0, g2++, 1023 < g2)))) return e3.sign ? -Infinity : 1 / 0;
            var c2 = e3.sign ? -2147483648 : 0;
            return g2 = g2 + 1023 << 20, o.__kBitConversionInts[1] = c2 | g2 | r2, o.__kBitConversionInts[0] = h2, o.__kBitConversionDouble[0];
          } }, { key: "unaryMinus", value: function(e3) {
            if (0 === e3.length) return e3;
            var t3 = e3.__copy();
            return t3.sign = !e3.sign, t3;
          } }, { key: "bitwiseNot", value: function(e3) {
            return e3.sign ? o.__absoluteSubOne(e3).__trim() : o.__absoluteAddOne(e3, true);
          } }, { key: "exponentiate", value: function(e3, t3) {
            if (t3.sign) throw new RangeError("Exponent must be positive");
            if (0 === t3.length) return o.__oneDigit(1, false);
            if (0 === e3.length) return e3;
            if (1 === e3.length && 1 === e3.__digit(0)) return e3.sign && 0 == (1 & t3.__digit(0)) ? o.unaryMinus(e3) : e3;
            if (1 < t3.length) throw new RangeError("BigInt too big");
            var i2 = t3.__unsignedDigit(0);
            if (1 === i2) return e3;
            if (i2 >= o.__kMaxLengthBits) throw new RangeError("BigInt too big");
            if (1 === e3.length && 2 === e3.__digit(0)) {
              var _2 = 1 + (i2 >>> 5), n3 = e3.sign && 0 != (1 & i2), l2 = new o(_2, n3);
              l2.__initializeDigits();
              var g2 = 1 << (31 & i2);
              return l2.__setDigit(_2 - 1, g2), l2;
            }
            var a3 = null, u2 = e3;
            for (0 != (1 & i2) && (a3 = e3), i2 >>= 1; 0 !== i2; i2 >>= 1) u2 = o.multiply(u2, u2), 0 != (1 & i2) && (null === a3 ? a3 = u2 : a3 = o.multiply(a3, u2));
            return a3;
          } }, { key: "multiply", value: function(e3, t3) {
            if (0 === e3.length) return e3;
            if (0 === t3.length) return t3;
            var _2 = e3.length + t3.length;
            32 <= e3.__clzmsd() + t3.__clzmsd() && _2--;
            var n3 = new o(_2, e3.sign !== t3.sign);
            n3.__initializeDigits();
            for (var l2 = 0; l2 < e3.length; l2++) o.__multiplyAccumulate(t3, e3.__digit(l2), n3, l2);
            return n3.__trim();
          } }, { key: "divide", value: function(e3, t3) {
            if (0 === t3.length) throw new RangeError("Division by zero");
            if (0 > o.__absoluteCompare(e3, t3)) return o.__zero();
            var i2, _2 = e3.sign !== t3.sign, n3 = t3.__unsignedDigit(0);
            if (1 === t3.length && 65535 >= n3) {
              if (1 === n3) return _2 === e3.sign ? e3 : o.unaryMinus(e3);
              i2 = o.__absoluteDivSmall(e3, n3, null);
            } else i2 = o.__absoluteDivLarge(e3, t3, true, false);
            return i2.sign = _2, i2.__trim();
          } }, { key: "remainder", value: function e3(t3, i2) {
            if (0 === i2.length) throw new RangeError("Division by zero");
            if (0 > o.__absoluteCompare(t3, i2)) return t3;
            var _2 = i2.__unsignedDigit(0);
            if (1 === i2.length && 65535 >= _2) {
              if (1 === _2) return o.__zero();
              var n3 = o.__absoluteModSmall(t3, _2);
              return 0 === n3 ? o.__zero() : o.__oneDigit(n3, t3.sign);
            }
            var e4 = o.__absoluteDivLarge(t3, i2, false, true);
            return e4.sign = t3.sign, e4.__trim();
          } }, { key: "add", value: function(e3, t3) {
            var i2 = e3.sign;
            return i2 === t3.sign ? o.__absoluteAdd(e3, t3, i2) : 0 <= o.__absoluteCompare(e3, t3) ? o.__absoluteSub(e3, t3, i2) : o.__absoluteSub(t3, e3, !i2);
          } }, { key: "subtract", value: function(e3, t3) {
            var i2 = e3.sign;
            return i2 === t3.sign ? 0 <= o.__absoluteCompare(e3, t3) ? o.__absoluteSub(e3, t3, i2) : o.__absoluteSub(t3, e3, !i2) : o.__absoluteAdd(e3, t3, i2);
          } }, { key: "leftShift", value: function(e3, t3) {
            return 0 === t3.length || 0 === e3.length ? e3 : t3.sign ? o.__rightShiftByAbsolute(e3, t3) : o.__leftShiftByAbsolute(e3, t3);
          } }, { key: "signedRightShift", value: function(e3, t3) {
            return 0 === t3.length || 0 === e3.length ? e3 : t3.sign ? o.__leftShiftByAbsolute(e3, t3) : o.__rightShiftByAbsolute(e3, t3);
          } }, { key: "unsignedRightShift", value: function() {
            throw new TypeError("BigInts have no unsigned right shift; use >> instead");
          } }, { key: "lessThan", value: function(e3, t3) {
            return 0 > o.__compareToBigInt(e3, t3);
          } }, { key: "lessThanOrEqual", value: function(e3, t3) {
            return 0 >= o.__compareToBigInt(e3, t3);
          } }, { key: "greaterThan", value: function(e3, t3) {
            return 0 < o.__compareToBigInt(e3, t3);
          } }, { key: "greaterThanOrEqual", value: function(e3, t3) {
            return 0 <= o.__compareToBigInt(e3, t3);
          } }, { key: "equal", value: function(e3, t3) {
            if (e3.sign !== t3.sign) return false;
            if (e3.length !== t3.length) return false;
            for (var _2 = 0; _2 < e3.length; _2++) if (e3.__digit(_2) !== t3.__digit(_2)) return false;
            return true;
          } }, { key: "notEqual", value: function(e3, t3) {
            return !o.equal(e3, t3);
          } }, { key: "bitwiseAnd", value: function(e3, t3) {
            if (!e3.sign && !t3.sign) return o.__absoluteAnd(e3, t3).__trim();
            if (e3.sign && t3.sign) {
              var i2 = n2(e3.length, t3.length) + 1, _2 = o.__absoluteSubOne(e3, i2), l2 = o.__absoluteSubOne(t3);
              return _2 = o.__absoluteOr(_2, l2, _2), o.__absoluteAddOne(_2, true, _2).__trim();
            }
            if (e3.sign) {
              var g2 = [t3, e3];
              e3 = g2[0], t3 = g2[1];
            }
            return o.__absoluteAndNot(e3, o.__absoluteSubOne(t3)).__trim();
          } }, { key: "bitwiseXor", value: function(e3, t3) {
            if (!e3.sign && !t3.sign) return o.__absoluteXor(e3, t3).__trim();
            if (e3.sign && t3.sign) {
              var i2 = n2(e3.length, t3.length), _2 = o.__absoluteSubOne(e3, i2), l2 = o.__absoluteSubOne(t3);
              return o.__absoluteXor(_2, l2, _2).__trim();
            }
            var g2 = n2(e3.length, t3.length) + 1;
            if (e3.sign) {
              var a3 = [t3, e3];
              e3 = a3[0], t3 = a3[1];
            }
            var u2 = o.__absoluteSubOne(t3, g2);
            return u2 = o.__absoluteXor(u2, e3, u2), o.__absoluteAddOne(u2, true, u2).__trim();
          } }, { key: "bitwiseOr", value: function(e3, t3) {
            var i2 = n2(e3.length, t3.length);
            if (!e3.sign && !t3.sign) return o.__absoluteOr(e3, t3).__trim();
            if (e3.sign && t3.sign) {
              var _2 = o.__absoluteSubOne(e3, i2), l2 = o.__absoluteSubOne(t3);
              return _2 = o.__absoluteAnd(_2, l2, _2), o.__absoluteAddOne(_2, true, _2).__trim();
            }
            if (e3.sign) {
              var g2 = [t3, e3];
              e3 = g2[0], t3 = g2[1];
            }
            var a3 = o.__absoluteSubOne(t3, i2);
            return a3 = o.__absoluteAndNot(a3, e3, a3), o.__absoluteAddOne(a3, true, a3).__trim();
          } }, { key: "asIntN", value: function(e3, t3) {
            if (0 === t3.length) return t3;
            if (0 === e3) return o.__zero();
            if (e3 >= o.__kMaxLengthBits) return t3;
            var _2 = e3 + 31 >>> 5;
            if (t3.length < _2) return t3;
            var n3 = t3.__unsignedDigit(_2 - 1), l2 = 1 << (31 & e3 - 1);
            if (t3.length === _2 && n3 < l2) return t3;
            if (!((n3 & l2) === l2)) return o.__truncateToNBits(e3, t3);
            if (!t3.sign) return o.__truncateAndSubFromPowerOfTwo(e3, t3, true);
            if (0 == (n3 & l2 - 1)) {
              for (var g2 = _2 - 2; 0 <= g2; g2--) if (0 !== t3.__digit(g2)) return o.__truncateAndSubFromPowerOfTwo(e3, t3, false);
              return t3.length === _2 && n3 === l2 ? t3 : o.__truncateToNBits(e3, t3);
            }
            return o.__truncateAndSubFromPowerOfTwo(e3, t3, false);
          } }, { key: "asUintN", value: function(e3, t3) {
            if (0 === t3.length) return t3;
            if (0 === e3) return o.__zero();
            if (t3.sign) {
              if (e3 > o.__kMaxLengthBits) throw new RangeError("BigInt too big");
              return o.__truncateAndSubFromPowerOfTwo(e3, t3, false);
            }
            if (e3 >= o.__kMaxLengthBits) return t3;
            var i2 = e3 + 31 >>> 5;
            if (t3.length < i2) return t3;
            var _2 = 31 & e3;
            if (t3.length == i2) {
              if (0 === _2) return t3;
              var n3 = t3.__digit(i2 - 1);
              if (0 == n3 >>> _2) return t3;
            }
            return o.__truncateToNBits(e3, t3);
          } }, { key: "ADD", value: function(e3, t3) {
            if (e3 = o.__toPrimitive(e3), t3 = o.__toPrimitive(t3), "string" == typeof e3) return "string" != typeof t3 && (t3 = t3.toString()), e3 + t3;
            if ("string" == typeof t3) return e3.toString() + t3;
            if (e3 = o.__toNumeric(e3), t3 = o.__toNumeric(t3), o.__isBigInt(e3) && o.__isBigInt(t3)) return o.add(e3, t3);
            if ("number" == typeof e3 && "number" == typeof t3) return e3 + t3;
            throw new TypeError("Cannot mix BigInt and other types, use explicit conversions");
          } }, { key: "LT", value: function(e3, t3) {
            return o.__compare(e3, t3, 0);
          } }, { key: "LE", value: function(e3, t3) {
            return o.__compare(e3, t3, 1);
          } }, { key: "GT", value: function(e3, t3) {
            return o.__compare(e3, t3, 2);
          } }, { key: "GE", value: function(e3, t3) {
            return o.__compare(e3, t3, 3);
          } }, { key: "EQ", value: function(e3, t3) {
            for (; ; ) {
              if (o.__isBigInt(e3)) return o.__isBigInt(t3) ? o.equal(e3, t3) : o.EQ(t3, e3);
              if ("number" == typeof e3) {
                if (o.__isBigInt(t3)) return o.__equalToNumber(t3, e3);
                if ("object" !== i(t3)) return e3 == t3;
                t3 = o.__toPrimitive(t3);
              } else if ("string" == typeof e3) {
                if (o.__isBigInt(t3)) return e3 = o.__fromString(e3), null !== e3 && o.equal(e3, t3);
                if ("object" !== i(t3)) return e3 == t3;
                t3 = o.__toPrimitive(t3);
              } else if ("boolean" == typeof e3) {
                if (o.__isBigInt(t3)) return o.__equalToNumber(t3, +e3);
                if ("object" !== i(t3)) return e3 == t3;
                t3 = o.__toPrimitive(t3);
              } else if ("symbol" === i(e3)) {
                if (o.__isBigInt(t3)) return false;
                if ("object" !== i(t3)) return e3 == t3;
                t3 = o.__toPrimitive(t3);
              } else if ("object" === i(e3)) {
                if ("object" === i(t3) && t3.constructor !== o) return e3 == t3;
                e3 = o.__toPrimitive(e3);
              } else return e3 == t3;
            }
          } }, { key: "NE", value: function(e3, t3) {
            return !o.EQ(e3, t3);
          } }, { key: "__zero", value: function() {
            return new o(0, false);
          } }, { key: "__oneDigit", value: function(e3, t3) {
            var i2 = new o(1, t3);
            return i2.__setDigit(0, e3), i2;
          } }, { key: "__decideRounding", value: function(e3, t3, i2, _2) {
            if (0 < t3) return -1;
            var n3;
            if (0 > t3) n3 = -t3 - 1;
            else {
              if (0 === i2) return -1;
              i2--, _2 = e3.__digit(i2), n3 = 31;
            }
            var l2 = 1 << n3;
            if (0 == (_2 & l2)) return -1;
            if (l2 -= 1, 0 != (_2 & l2)) return 1;
            for (; 0 < i2; ) if (i2--, 0 !== e3.__digit(i2)) return 1;
            return 0;
          } }, { key: "__fromDouble", value: function(e3) {
            o.__kBitConversionDouble[0] = e3;
            var t3, i2 = 2047 & o.__kBitConversionInts[1] >>> 20, _2 = i2 - 1023, n3 = (_2 >>> 5) + 1, l2 = new o(n3, 0 > e3), g2 = 1048575 & o.__kBitConversionInts[1] | 1048576, a3 = o.__kBitConversionInts[0], u2 = 20, s2 = 31 & _2, r2 = 0;
            if (s2 < u2) {
              var d2 = u2 - s2;
              r2 = d2 + 32, t3 = g2 >>> d2, g2 = g2 << 32 - d2 | a3 >>> d2, a3 <<= 32 - d2;
            } else if (s2 === u2) r2 = 32, t3 = g2, g2 = a3;
            else {
              var h2 = s2 - u2;
              r2 = 32 - h2, t3 = g2 << h2 | a3 >>> 32 - h2, g2 = a3 << h2;
            }
            l2.__setDigit(n3 - 1, t3);
            for (var b2 = n3 - 2; 0 <= b2; b2--) 0 < r2 ? (r2 -= 32, t3 = g2, g2 = a3) : t3 = 0, l2.__setDigit(b2, t3);
            return l2.__trim();
          } }, { key: "__isWhitespace", value: function(e3) {
            return !!(13 >= e3 && 9 <= e3) || (159 >= e3 ? 32 == e3 : 131071 >= e3 ? 160 == e3 || 5760 == e3 : 196607 >= e3 ? (e3 &= 131071, 10 >= e3 || 40 == e3 || 41 == e3 || 47 == e3 || 95 == e3 || 4096 == e3) : 65279 == e3);
          } }, { key: "__fromString", value: function(e3) {
            var t3 = 1 < arguments.length && void 0 !== arguments[1] ? arguments[1] : 0, i2 = 0, _2 = e3.length, n3 = 0;
            if (n3 === _2) return o.__zero();
            for (var l2 = e3.charCodeAt(n3); o.__isWhitespace(l2); ) {
              if (++n3 === _2) return o.__zero();
              l2 = e3.charCodeAt(n3);
            }
            if (43 === l2) {
              if (++n3 === _2) return null;
              l2 = e3.charCodeAt(n3), i2 = 1;
            } else if (45 === l2) {
              if (++n3 === _2) return null;
              l2 = e3.charCodeAt(n3), i2 = -1;
            }
            if (0 === t3) {
              if (t3 = 10, 48 === l2) {
                if (++n3 === _2) return o.__zero();
                if (l2 = e3.charCodeAt(n3), 88 === l2 || 120 === l2) {
                  if (t3 = 16, ++n3 === _2) return null;
                  l2 = e3.charCodeAt(n3);
                } else if (79 === l2 || 111 === l2) {
                  if (t3 = 8, ++n3 === _2) return null;
                  l2 = e3.charCodeAt(n3);
                } else if (66 === l2 || 98 === l2) {
                  if (t3 = 2, ++n3 === _2) return null;
                  l2 = e3.charCodeAt(n3);
                }
              }
            } else if (16 === t3 && 48 === l2) {
              if (++n3 === _2) return o.__zero();
              if (l2 = e3.charCodeAt(n3), 88 === l2 || 120 === l2) {
                if (++n3 === _2) return null;
                l2 = e3.charCodeAt(n3);
              }
            }
            for (; 48 === l2; ) {
              if (++n3 === _2) return o.__zero();
              l2 = e3.charCodeAt(n3);
            }
            var g2 = _2 - n3, a3 = o.__kMaxBitsPerChar[t3], u2 = o.__kBitsPerCharTableMultiplier - 1;
            if (g2 > 1073741824 / a3) return null;
            var s2 = a3 * g2 + u2 >>> o.__kBitsPerCharTableShift, r2 = new o(s2 + 31 >>> 5, false), h2 = 10 > t3 ? t3 : 10, b2 = 10 < t3 ? t3 - 10 : 0;
            if (0 == (t3 & t3 - 1)) {
              a3 >>= o.__kBitsPerCharTableShift;
              var c2 = [], v2 = [], f2 = false;
              do {
                for (var y2, k2 = 0, D = 0; ; ) {
                  if (y2 = void 0, l2 - 48 >>> 0 < h2) y2 = l2 - 48;
                  else if ((32 | l2) - 97 >>> 0 < b2) y2 = (32 | l2) - 87;
                  else {
                    f2 = true;
                    break;
                  }
                  if (D += a3, k2 = k2 << a3 | y2, ++n3 === _2) {
                    f2 = true;
                    break;
                  }
                  if (l2 = e3.charCodeAt(n3), 32 < D + a3) break;
                }
                c2.push(k2), v2.push(D);
              } while (!f2);
              o.__fillFromParts(r2, c2, v2);
            } else {
              r2.__initializeDigits();
              var p = false, B = 0;
              do {
                for (var S, C = 0, A = 1; ; ) {
                  if (S = void 0, l2 - 48 >>> 0 < h2) S = l2 - 48;
                  else if ((32 | l2) - 97 >>> 0 < b2) S = (32 | l2) - 87;
                  else {
                    p = true;
                    break;
                  }
                  var T = A * t3;
                  if (4294967295 < T) break;
                  if (A = T, C = C * t3 + S, B++, ++n3 === _2) {
                    p = true;
                    break;
                  }
                  l2 = e3.charCodeAt(n3);
                }
                u2 = 32 * o.__kBitsPerCharTableMultiplier - 1;
                var m2 = a3 * B + u2 >>> o.__kBitsPerCharTableShift + 5;
                r2.__inplaceMultiplyAdd(A, C, m2);
              } while (!p);
            }
            if (n3 !== _2) {
              if (!o.__isWhitespace(l2)) return null;
              for (n3++; n3 < _2; n3++) if (l2 = e3.charCodeAt(n3), !o.__isWhitespace(l2)) return null;
            }
            return 0 !== i2 && 10 !== t3 ? null : (r2.sign = -1 === i2, r2.__trim());
          } }, { key: "__fillFromParts", value: function(e3, t3, _2) {
            for (var n3 = 0, l2 = 0, g2 = 0, o2 = t3.length - 1; 0 <= o2; o2--) {
              var a3 = t3[o2], u2 = _2[o2];
              l2 |= a3 << g2, g2 += u2, 32 === g2 ? (e3.__setDigit(n3++, l2), g2 = 0, l2 = 0) : 32 < g2 && (e3.__setDigit(n3++, l2), g2 -= 32, l2 = a3 >>> u2 - g2);
            }
            if (0 !== l2) {
              if (n3 >= e3.length) throw new Error("implementation bug");
              e3.__setDigit(n3++, l2);
            }
            for (; n3 < e3.length; n3++) e3.__setDigit(n3, 0);
          } }, { key: "__toStringBasePowerOfTwo", value: function(e3, t3) {
            var _2 = e3.length, n3 = t3 - 1;
            n3 = (85 & n3 >>> 1) + (85 & n3), n3 = (51 & n3 >>> 2) + (51 & n3), n3 = (15 & n3 >>> 4) + (15 & n3);
            var l2 = n3, g2 = t3 - 1, a3 = e3.__digit(_2 - 1), u2 = o.__clz32(a3), s2 = 0 | (32 * _2 - u2 + l2 - 1) / l2;
            if (e3.sign && s2++, 268435456 < s2) throw new Error("string too long");
            for (var r2 = Array(s2), d2 = s2 - 1, h2 = 0, b2 = 0, m2 = 0; m2 < _2 - 1; m2++) {
              var c2 = e3.__digit(m2), v2 = (h2 | c2 << b2) & g2;
              r2[d2--] = o.__kConversionChars[v2];
              var f2 = l2 - b2;
              for (h2 = c2 >>> f2, b2 = 32 - f2; b2 >= l2; ) r2[d2--] = o.__kConversionChars[h2 & g2], h2 >>>= l2, b2 -= l2;
            }
            var y2 = (h2 | a3 << b2) & g2;
            for (r2[d2--] = o.__kConversionChars[y2], h2 = a3 >>> l2 - b2; 0 !== h2; ) r2[d2--] = o.__kConversionChars[h2 & g2], h2 >>>= l2;
            if (e3.sign && (r2[d2--] = "-"), -1 !== d2) throw new Error("implementation bug");
            return r2.join("");
          } }, { key: "__toStringGeneric", value: function(e3, t3, _2) {
            var n3 = e3.length;
            if (0 === n3) return "";
            if (1 === n3) {
              var l2 = e3.__unsignedDigit(0).toString(t3);
              return false === _2 && e3.sign && (l2 = "-" + l2), l2;
            }
            var g2 = 32 * n3 - o.__clz32(e3.__digit(n3 - 1)), a3 = o.__kMaxBitsPerChar[t3], u2 = a3 - 1, s2 = g2 * o.__kBitsPerCharTableMultiplier;
            s2 += u2 - 1, s2 = 0 | s2 / u2;
            var r2, d2, h2 = s2 + 1 >> 1, b2 = o.exponentiate(o.__oneDigit(t3, false), o.__oneDigit(h2, false)), m2 = b2.__unsignedDigit(0);
            if (1 === b2.length && 65535 >= m2) {
              r2 = new o(e3.length, false), r2.__initializeDigits();
              for (var c2, v2 = 0, f2 = 2 * e3.length - 1; 0 <= f2; f2--) c2 = v2 << 16 | e3.__halfDigit(f2), r2.__setHalfDigit(f2, 0 | c2 / m2), v2 = 0 | c2 % m2;
              d2 = v2.toString(t3);
            } else {
              var y2 = o.__absoluteDivLarge(e3, b2, true, true);
              r2 = y2.quotient;
              var k2 = y2.remainder.__trim();
              d2 = o.__toStringGeneric(k2, t3, true);
            }
            r2.__trim();
            for (var D = o.__toStringGeneric(r2, t3, true); d2.length < h2; ) d2 = "0" + d2;
            return false === _2 && e3.sign && (D = "-" + D), D + d2;
          } }, { key: "__unequalSign", value: function(e3) {
            return e3 ? -1 : 1;
          } }, { key: "__absoluteGreater", value: function(e3) {
            return e3 ? -1 : 1;
          } }, { key: "__absoluteLess", value: function(e3) {
            return e3 ? 1 : -1;
          } }, { key: "__compareToBigInt", value: function(e3, t3) {
            var i2 = e3.sign;
            if (i2 !== t3.sign) return o.__unequalSign(i2);
            var _2 = o.__absoluteCompare(e3, t3);
            return 0 < _2 ? o.__absoluteGreater(i2) : 0 > _2 ? o.__absoluteLess(i2) : 0;
          } }, { key: "__compareToNumber", value: function(e3, i2) {
            if (true | i2) {
              var _2 = e3.sign, n3 = 0 > i2;
              if (_2 !== n3) return o.__unequalSign(_2);
              if (0 === e3.length) {
                if (n3) throw new Error("implementation bug");
                return 0 === i2 ? 0 : -1;
              }
              if (1 < e3.length) return o.__absoluteGreater(_2);
              var l2 = t2(i2), g2 = e3.__unsignedDigit(0);
              return g2 > l2 ? o.__absoluteGreater(_2) : g2 < l2 ? o.__absoluteLess(_2) : 0;
            }
            return o.__compareToDouble(e3, i2);
          } }, { key: "__compareToDouble", value: function(e3, t3) {
            if (t3 !== t3) return t3;
            if (t3 === 1 / 0) return -1;
            if (t3 === -Infinity) return 1;
            var i2 = e3.sign;
            if (i2 !== 0 > t3) return o.__unequalSign(i2);
            if (0 === t3) throw new Error("implementation bug: should be handled elsewhere");
            if (0 === e3.length) return -1;
            o.__kBitConversionDouble[0] = t3;
            var _2 = 2047 & o.__kBitConversionInts[1] >>> 20;
            if (2047 == _2) throw new Error("implementation bug: handled elsewhere");
            var n3 = _2 - 1023;
            if (0 > n3) return o.__absoluteGreater(i2);
            var l2 = e3.length, g2 = e3.__digit(l2 - 1), a3 = o.__clz32(g2), u2 = 32 * l2 - a3, s2 = n3 + 1;
            if (u2 < s2) return o.__absoluteLess(i2);
            if (u2 > s2) return o.__absoluteGreater(i2);
            var r2 = 1048576 | 1048575 & o.__kBitConversionInts[1], d2 = o.__kBitConversionInts[0], h2 = 20, b2 = 31 - a3;
            if (b2 !== (u2 - 1) % 31) throw new Error("implementation bug");
            var m2, c2 = 0;
            if (b2 < h2) {
              var v2 = h2 - b2;
              c2 = v2 + 32, m2 = r2 >>> v2, r2 = r2 << 32 - v2 | d2 >>> v2, d2 <<= 32 - v2;
            } else if (b2 === h2) c2 = 32, m2 = r2, r2 = d2;
            else {
              var f2 = b2 - h2;
              c2 = 32 - f2, m2 = r2 << f2 | d2 >>> 32 - f2, r2 = d2 << f2;
            }
            if (g2 >>>= 0, m2 >>>= 0, g2 > m2) return o.__absoluteGreater(i2);
            if (g2 < m2) return o.__absoluteLess(i2);
            for (var y2 = l2 - 2; 0 <= y2; y2--) {
              0 < c2 ? (c2 -= 32, m2 = r2 >>> 0, r2 = d2, d2 = 0) : m2 = 0;
              var k2 = e3.__unsignedDigit(y2);
              if (k2 > m2) return o.__absoluteGreater(i2);
              if (k2 < m2) return o.__absoluteLess(i2);
            }
            if (0 !== r2 || 0 !== d2) {
              if (0 === c2) throw new Error("implementation bug");
              return o.__absoluteLess(i2);
            }
            return 0;
          } }, { key: "__equalToNumber", value: function(e3, i2) {
            return i2 | 0 === i2 ? 0 === i2 ? 0 === e3.length : 1 === e3.length && e3.sign === 0 > i2 && e3.__unsignedDigit(0) === t2(i2) : 0 === o.__compareToDouble(e3, i2);
          } }, { key: "__comparisonResultToBool", value: function(e3, t3) {
            switch (t3) {
              case 0:
                return 0 > e3;
              case 1:
                return 0 >= e3;
              case 2:
                return 0 < e3;
              case 3:
                return 0 <= e3;
            }
            throw new Error("unreachable");
          } }, { key: "__compare", value: function(e3, t3, i2) {
            if (e3 = o.__toPrimitive(e3), t3 = o.__toPrimitive(t3), "string" == typeof e3 && "string" == typeof t3) switch (i2) {
              case 0:
                return e3 < t3;
              case 1:
                return e3 <= t3;
              case 2:
                return e3 > t3;
              case 3:
                return e3 >= t3;
            }
            if (o.__isBigInt(e3) && "string" == typeof t3) return t3 = o.__fromString(t3), null !== t3 && o.__comparisonResultToBool(o.__compareToBigInt(e3, t3), i2);
            if ("string" == typeof e3 && o.__isBigInt(t3)) return e3 = o.__fromString(e3), null !== e3 && o.__comparisonResultToBool(o.__compareToBigInt(e3, t3), i2);
            if (e3 = o.__toNumeric(e3), t3 = o.__toNumeric(t3), o.__isBigInt(e3)) {
              if (o.__isBigInt(t3)) return o.__comparisonResultToBool(o.__compareToBigInt(e3, t3), i2);
              if ("number" != typeof t3) throw new Error("implementation bug");
              return o.__comparisonResultToBool(o.__compareToNumber(e3, t3), i2);
            }
            if ("number" != typeof e3) throw new Error("implementation bug");
            if (o.__isBigInt(t3)) return o.__comparisonResultToBool(o.__compareToNumber(t3, e3), 2 ^ i2);
            if ("number" != typeof t3) throw new Error("implementation bug");
            return 0 === i2 ? e3 < t3 : 1 === i2 ? e3 <= t3 : 2 === i2 ? e3 > t3 : 3 === i2 ? e3 >= t3 : void 0;
          } }, { key: "__absoluteAdd", value: function(e3, t3, _2) {
            if (e3.length < t3.length) return o.__absoluteAdd(t3, e3, _2);
            if (0 === e3.length) return e3;
            if (0 === t3.length) return e3.sign === _2 ? e3 : o.unaryMinus(e3);
            var n3 = e3.length;
            (0 === e3.__clzmsd() || t3.length === e3.length && 0 === t3.__clzmsd()) && n3++;
            for (var l2 = new o(n3, _2), g2 = 0, a3 = 0; a3 < t3.length; a3++) {
              var u2 = t3.__digit(a3), s2 = e3.__digit(a3), r2 = (65535 & s2) + (65535 & u2) + g2, d2 = (s2 >>> 16) + (u2 >>> 16) + (r2 >>> 16);
              g2 = d2 >>> 16, l2.__setDigit(a3, 65535 & r2 | d2 << 16);
            }
            for (; a3 < e3.length; a3++) {
              var h2 = e3.__digit(a3), b2 = (65535 & h2) + g2, m2 = (h2 >>> 16) + (b2 >>> 16);
              g2 = m2 >>> 16, l2.__setDigit(a3, 65535 & b2 | m2 << 16);
            }
            return a3 < l2.length && l2.__setDigit(a3, g2), l2.__trim();
          } }, { key: "__absoluteSub", value: function(e3, t3, _2) {
            if (0 === e3.length) return e3;
            if (0 === t3.length) return e3.sign === _2 ? e3 : o.unaryMinus(e3);
            for (var n3 = new o(e3.length, _2), l2 = 0, g2 = 0; g2 < t3.length; g2++) {
              var a3 = e3.__digit(g2), u2 = t3.__digit(g2), s2 = (65535 & a3) - (65535 & u2) - l2;
              l2 = 1 & s2 >>> 16;
              var r2 = (a3 >>> 16) - (u2 >>> 16) - l2;
              l2 = 1 & r2 >>> 16, n3.__setDigit(g2, 65535 & s2 | r2 << 16);
            }
            for (; g2 < e3.length; g2++) {
              var d2 = e3.__digit(g2), h2 = (65535 & d2) - l2;
              l2 = 1 & h2 >>> 16;
              var b2 = (d2 >>> 16) - l2;
              l2 = 1 & b2 >>> 16, n3.__setDigit(g2, 65535 & h2 | b2 << 16);
            }
            return n3.__trim();
          } }, { key: "__absoluteAddOne", value: function(e3, t3) {
            var _2 = 2 < arguments.length && void 0 !== arguments[2] ? arguments[2] : null, n3 = e3.length;
            null === _2 ? _2 = new o(n3, t3) : _2.sign = t3;
            for (var l2, g2 = true, a3 = 0; a3 < n3; a3++) {
              if (l2 = e3.__digit(a3), g2) {
                var u2 = -1 === l2;
                l2 = 0 | l2 + 1, g2 = u2;
              }
              _2.__setDigit(a3, l2);
            }
            return g2 && _2.__setDigitGrow(n3, 1), _2;
          } }, { key: "__absoluteSubOne", value: function(e3, t3) {
            var _2 = e3.length;
            t3 = t3 || _2;
            for (var n3, l2 = new o(t3, false), g2 = true, a3 = 0; a3 < _2; a3++) {
              if (n3 = e3.__digit(a3), g2) {
                var u2 = 0 === n3;
                n3 = 0 | n3 - 1, g2 = u2;
              }
              l2.__setDigit(a3, n3);
            }
            if (g2) throw new Error("implementation bug");
            for (var s2 = _2; s2 < t3; s2++) l2.__setDigit(s2, 0);
            return l2;
          } }, { key: "__absoluteAnd", value: function(e3, t3) {
            var _2 = 2 < arguments.length && void 0 !== arguments[2] ? arguments[2] : null, n3 = e3.length, l2 = t3.length, g2 = l2;
            if (n3 < l2) {
              g2 = n3;
              var a3 = e3, u2 = n3;
              e3 = t3, n3 = l2, t3 = a3, l2 = u2;
            }
            var s2 = g2;
            null === _2 ? _2 = new o(s2, false) : s2 = _2.length;
            for (var r2 = 0; r2 < g2; r2++) _2.__setDigit(r2, e3.__digit(r2) & t3.__digit(r2));
            for (; r2 < s2; r2++) _2.__setDigit(r2, 0);
            return _2;
          } }, { key: "__absoluteAndNot", value: function(e3, t3) {
            var _2 = 2 < arguments.length && void 0 !== arguments[2] ? arguments[2] : null, n3 = e3.length, l2 = t3.length, g2 = l2;
            n3 < l2 && (g2 = n3);
            var a3 = n3;
            null === _2 ? _2 = new o(a3, false) : a3 = _2.length;
            for (var u2 = 0; u2 < g2; u2++) _2.__setDigit(u2, e3.__digit(u2) & ~t3.__digit(u2));
            for (; u2 < n3; u2++) _2.__setDigit(u2, e3.__digit(u2));
            for (; u2 < a3; u2++) _2.__setDigit(u2, 0);
            return _2;
          } }, { key: "__absoluteOr", value: function(e3, t3) {
            var _2 = 2 < arguments.length && void 0 !== arguments[2] ? arguments[2] : null, n3 = e3.length, l2 = t3.length, g2 = l2;
            if (n3 < l2) {
              g2 = n3;
              var a3 = e3, u2 = n3;
              e3 = t3, n3 = l2, t3 = a3, l2 = u2;
            }
            var s2 = n3;
            null === _2 ? _2 = new o(s2, false) : s2 = _2.length;
            for (var r2 = 0; r2 < g2; r2++) _2.__setDigit(r2, e3.__digit(r2) | t3.__digit(r2));
            for (; r2 < n3; r2++) _2.__setDigit(r2, e3.__digit(r2));
            for (; r2 < s2; r2++) _2.__setDigit(r2, 0);
            return _2;
          } }, { key: "__absoluteXor", value: function(e3, t3) {
            var _2 = 2 < arguments.length && void 0 !== arguments[2] ? arguments[2] : null, n3 = e3.length, l2 = t3.length, g2 = l2;
            if (n3 < l2) {
              g2 = n3;
              var a3 = e3, u2 = n3;
              e3 = t3, n3 = l2, t3 = a3, l2 = u2;
            }
            var s2 = n3;
            null === _2 ? _2 = new o(s2, false) : s2 = _2.length;
            for (var r2 = 0; r2 < g2; r2++) _2.__setDigit(r2, e3.__digit(r2) ^ t3.__digit(r2));
            for (; r2 < n3; r2++) _2.__setDigit(r2, e3.__digit(r2));
            for (; r2 < s2; r2++) _2.__setDigit(r2, 0);
            return _2;
          } }, { key: "__absoluteCompare", value: function(e3, t3) {
            var _2 = e3.length - t3.length;
            if (0 != _2) return _2;
            for (var n3 = e3.length - 1; 0 <= n3 && e3.__digit(n3) === t3.__digit(n3); ) n3--;
            return 0 > n3 ? 0 : e3.__unsignedDigit(n3) > t3.__unsignedDigit(n3) ? 1 : -1;
          } }, { key: "__multiplyAccumulate", value: function(e3, t3, _2, n3) {
            if (0 !== t3) {
              for (var l2 = 65535 & t3, g2 = t3 >>> 16, a3 = 0, u2 = 0, s2 = 0, r2 = 0; r2 < e3.length; r2++, n3++) {
                var d2 = _2.__digit(n3), h2 = 65535 & d2, b2 = d2 >>> 16, m2 = e3.__digit(r2), c2 = 65535 & m2, v2 = m2 >>> 16, f2 = o.__imul(c2, l2), y2 = o.__imul(c2, g2), k2 = o.__imul(v2, l2), D = o.__imul(v2, g2);
                h2 += u2 + (65535 & f2), b2 += s2 + a3 + (h2 >>> 16) + (f2 >>> 16) + (65535 & y2) + (65535 & k2), a3 = b2 >>> 16, u2 = (y2 >>> 16) + (k2 >>> 16) + (65535 & D) + a3, a3 = u2 >>> 16, u2 &= 65535, s2 = D >>> 16, d2 = 65535 & h2 | b2 << 16, _2.__setDigit(n3, d2);
              }
              for (; 0 !== a3 || 0 !== u2 || 0 !== s2; n3++) {
                var p = _2.__digit(n3), B = (65535 & p) + u2, S = (p >>> 16) + (B >>> 16) + s2 + a3;
                u2 = 0, s2 = 0, a3 = S >>> 16, p = 65535 & B | S << 16, _2.__setDigit(n3, p);
              }
            }
          } }, { key: "__internalMultiplyAdd", value: function(e3, t3, _2, l2, g2) {
            for (var a3 = _2, u2 = 0, s2 = 0; s2 < l2; s2++) {
              var r2 = e3.__digit(s2), d2 = o.__imul(65535 & r2, t3), h2 = (65535 & d2) + u2 + a3;
              a3 = h2 >>> 16;
              var b2 = o.__imul(r2 >>> 16, t3), m2 = (65535 & b2) + (d2 >>> 16) + a3;
              a3 = m2 >>> 16, u2 = b2 >>> 16, g2.__setDigit(s2, m2 << 16 | 65535 & h2);
            }
            if (g2.length > l2) for (g2.__setDigit(l2++, a3 + u2); l2 < g2.length; ) g2.__setDigit(l2++, 0);
            else if (0 !== a3 + u2) throw new Error("implementation bug");
          } }, { key: "__absoluteDivSmall", value: function(e3, t3, _2) {
            null === _2 && (_2 = new o(e3.length, false));
            for (var n3 = 0, l2 = 2 * e3.length - 1; 0 <= l2; l2 -= 2) {
              var g2 = (n3 << 16 | e3.__halfDigit(l2)) >>> 0, a3 = 0 | g2 / t3;
              n3 = 0 | g2 % t3, g2 = (n3 << 16 | e3.__halfDigit(l2 - 1)) >>> 0;
              var u2 = 0 | g2 / t3;
              n3 = 0 | g2 % t3, _2.__setDigit(l2 >>> 1, a3 << 16 | u2);
            }
            return _2;
          } }, { key: "__absoluteModSmall", value: function(e3, t3) {
            for (var _2, n3 = 0, l2 = 2 * e3.length - 1; 0 <= l2; l2--) _2 = (n3 << 16 | e3.__halfDigit(l2)) >>> 0, n3 = 0 | _2 % t3;
            return n3;
          } }, { key: "__absoluteDivLarge", value: function(e3, t3, i2, _2) {
            var l2 = t3.__halfDigitLength(), n3 = t3.length, g2 = e3.__halfDigitLength() - l2, a3 = null;
            i2 && (a3 = new o(g2 + 2 >>> 1, false), a3.__initializeDigits());
            var s2 = new o(l2 + 2 >>> 1, false);
            s2.__initializeDigits();
            var r2 = o.__clz16(t3.__halfDigit(l2 - 1));
            0 < r2 && (t3 = o.__specialLeftShift(t3, r2, 0));
            for (var d2 = o.__specialLeftShift(e3, r2, 1), u2 = t3.__halfDigit(l2 - 1), h2 = 0, b2 = g2; 0 <= b2; b2--) {
              var m2 = 65535, v2 = d2.__halfDigit(b2 + l2);
              if (v2 !== u2) {
                var f2 = (v2 << 16 | d2.__halfDigit(b2 + l2 - 1)) >>> 0;
                m2 = 0 | f2 / u2;
                for (var y2 = 0 | f2 % u2, k2 = t3.__halfDigit(l2 - 2), D = d2.__halfDigit(b2 + l2 - 2); o.__imul(m2, k2) >>> 0 > (y2 << 16 | D) >>> 0 && (m2--, y2 += u2, !(65535 < y2)); ) ;
              }
              o.__internalMultiplyAdd(t3, m2, 0, n3, s2);
              var p = d2.__inplaceSub(s2, b2, l2 + 1);
              0 !== p && (p = d2.__inplaceAdd(t3, b2, l2), d2.__setHalfDigit(b2 + l2, d2.__halfDigit(b2 + l2) + p), m2--), i2 && (1 & b2 ? h2 = m2 << 16 : a3.__setDigit(b2 >>> 1, h2 | m2));
            }
            return _2 ? (d2.__inplaceRightShift(r2), i2 ? { quotient: a3, remainder: d2 } : d2) : i2 ? a3 : void 0;
          } }, { key: "__clz16", value: function(e3) {
            return o.__clz32(e3) - 16;
          } }, { key: "__specialLeftShift", value: function(e3, t3, _2) {
            var l2 = e3.length, n3 = new o(l2 + _2, false);
            if (0 === t3) {
              for (var g2 = 0; g2 < l2; g2++) n3.__setDigit(g2, e3.__digit(g2));
              return 0 < _2 && n3.__setDigit(l2, 0), n3;
            }
            for (var a3, u2 = 0, s2 = 0; s2 < l2; s2++) a3 = e3.__digit(s2), n3.__setDigit(s2, a3 << t3 | u2), u2 = a3 >>> 32 - t3;
            return 0 < _2 && n3.__setDigit(l2, u2), n3;
          } }, { key: "__leftShiftByAbsolute", value: function(e3, t3) {
            var _2 = o.__toShiftAmount(t3);
            if (0 > _2) throw new RangeError("BigInt too big");
            var n3 = _2 >>> 5, l2 = 31 & _2, g2 = e3.length, a3 = 0 !== l2 && 0 != e3.__digit(g2 - 1) >>> 32 - l2, u2 = g2 + n3 + (a3 ? 1 : 0), s2 = new o(u2, e3.sign);
            if (0 === l2) {
              for (var r2 = 0; r2 < n3; r2++) s2.__setDigit(r2, 0);
              for (; r2 < u2; r2++) s2.__setDigit(r2, e3.__digit(r2 - n3));
            } else {
              for (var h2 = 0, b2 = 0; b2 < n3; b2++) s2.__setDigit(b2, 0);
              for (var m2, c2 = 0; c2 < g2; c2++) m2 = e3.__digit(c2), s2.__setDigit(c2 + n3, m2 << l2 | h2), h2 = m2 >>> 32 - l2;
              if (a3) s2.__setDigit(g2 + n3, h2);
              else if (0 !== h2) throw new Error("implementation bug");
            }
            return s2.__trim();
          } }, { key: "__rightShiftByAbsolute", value: function(e3, t3) {
            var _2 = e3.length, n3 = e3.sign, l2 = o.__toShiftAmount(t3);
            if (0 > l2) return o.__rightShiftByMaximum(n3);
            var g2 = l2 >>> 5, a3 = 31 & l2, u2 = _2 - g2;
            if (0 >= u2) return o.__rightShiftByMaximum(n3);
            var s2 = false;
            if (n3) {
              if (0 != (e3.__digit(g2) & (1 << a3) - 1)) s2 = true;
              else for (var r2 = 0; r2 < g2; r2++) if (0 !== e3.__digit(r2)) {
                s2 = true;
                break;
              }
            }
            if (s2 && 0 === a3) {
              var h2 = e3.__digit(_2 - 1);
              0 == ~h2 && u2++;
            }
            var b2 = new o(u2, n3);
            if (0 === a3) for (var m2 = g2; m2 < _2; m2++) b2.__setDigit(m2 - g2, e3.__digit(m2));
            else {
              for (var c2, v2 = e3.__digit(g2) >>> a3, f2 = _2 - g2 - 1, y2 = 0; y2 < f2; y2++) c2 = e3.__digit(y2 + g2 + 1), b2.__setDigit(y2, c2 << 32 - a3 | v2), v2 = c2 >>> a3;
              b2.__setDigit(f2, v2);
            }
            return s2 && (b2 = o.__absoluteAddOne(b2, true, b2)), b2.__trim();
          } }, { key: "__rightShiftByMaximum", value: function(e3) {
            return e3 ? o.__oneDigit(1, true) : o.__zero();
          } }, { key: "__toShiftAmount", value: function(e3) {
            if (1 < e3.length) return -1;
            var t3 = e3.__unsignedDigit(0);
            return t3 > o.__kMaxLengthBits ? -1 : t3;
          } }, { key: "__toPrimitive", value: function(e3) {
            var t3 = 1 < arguments.length && void 0 !== arguments[1] ? arguments[1] : "default";
            if ("object" !== i(e3)) return e3;
            if (e3.constructor === o) return e3;
            var _2 = e3[Symbol.toPrimitive];
            if (_2) {
              var n3 = _2(t3);
              if ("object" !== i(n3)) return n3;
              throw new TypeError("Cannot convert object to primitive value");
            }
            var l2 = e3.valueOf;
            if (l2) {
              var g2 = l2.call(e3);
              if ("object" !== i(g2)) return g2;
            }
            var a3 = e3.toString;
            if (a3) {
              var u2 = a3.call(e3);
              if ("object" !== i(u2)) return u2;
            }
            throw new TypeError("Cannot convert object to primitive value");
          } }, { key: "__toNumeric", value: function(e3) {
            return o.__isBigInt(e3) ? e3 : +e3;
          } }, { key: "__isBigInt", value: function(e3) {
            return "object" === i(e3) && null !== e3 && e3.constructor === o;
          } }, { key: "__truncateToNBits", value: function(e3, t3) {
            for (var _2 = e3 + 31 >>> 5, n3 = new o(_2, t3.sign), l2 = _2 - 1, g2 = 0; g2 < l2; g2++) n3.__setDigit(g2, t3.__digit(g2));
            var a3 = t3.__digit(l2);
            if (0 != (31 & e3)) {
              var u2 = 32 - (31 & e3);
              a3 = a3 << u2 >>> u2;
            }
            return n3.__setDigit(l2, a3), n3.__trim();
          } }, { key: "__truncateAndSubFromPowerOfTwo", value: function(e3, t3, _2) {
            for (var n3 = Math.min, l2 = e3 + 31 >>> 5, g2 = new o(l2, _2), a3 = 0, u2 = l2 - 1, s2 = 0, r2 = n3(u2, t3.length); a3 < r2; a3++) {
              var d2 = t3.__digit(a3), h2 = 0 - (65535 & d2) - s2;
              s2 = 1 & h2 >>> 16;
              var b2 = 0 - (d2 >>> 16) - s2;
              s2 = 1 & b2 >>> 16, g2.__setDigit(a3, 65535 & h2 | b2 << 16);
            }
            for (; a3 < u2; a3++) g2.__setDigit(a3, 0 | -s2);
            var m2, c2 = u2 < t3.length ? t3.__digit(u2) : 0, v2 = 31 & e3;
            if (0 === v2) {
              var f2 = 0 - (65535 & c2) - s2;
              s2 = 1 & f2 >>> 16;
              var y2 = 0 - (c2 >>> 16) - s2;
              m2 = 65535 & f2 | y2 << 16;
            } else {
              var k2 = 32 - v2;
              c2 = c2 << k2 >>> k2;
              var D = 1 << 32 - k2, p = (65535 & D) - (65535 & c2) - s2;
              s2 = 1 & p >>> 16;
              var B = (D >>> 16) - (c2 >>> 16) - s2;
              m2 = 65535 & p | B << 16, m2 &= D - 1;
            }
            return g2.__setDigit(u2, m2), g2.__trim();
          } }, { key: "__digitPow", value: function(e3, t3) {
            for (var i2 = 1; 0 < t3; ) 1 & t3 && (i2 *= e3), t3 >>>= 1, e3 *= e3;
            return i2;
          } }]), o;
        })(h(Array));
        return k.__kMaxLength = 33554432, k.__kMaxLengthBits = k.__kMaxLength << 5, k.__kMaxBitsPerChar = [0, 0, 32, 51, 64, 75, 83, 90, 96, 102, 107, 111, 115, 119, 122, 126, 128, 131, 134, 136, 139, 141, 143, 145, 147, 149, 151, 153, 154, 156, 158, 159, 160, 162, 163, 165, 166], k.__kBitsPerCharTableShift = 5, k.__kBitsPerCharTableMultiplier = 1 << k.__kBitsPerCharTableShift, k.__kConversionChars = ["0", "1", "2", "3", "4", "5", "6", "7", "8", "9", "a", "b", "c", "d", "e", "f", "g", "h", "i", "j", "k", "l", "m", "n", "o", "p", "q", "r", "s", "t", "u", "v", "w", "x", "y", "z"], k.__kBitConversionBuffer = new ArrayBuffer(8), k.__kBitConversionDouble = new Float64Array(k.__kBitConversionBuffer), k.__kBitConversionInts = new Int32Array(k.__kBitConversionBuffer), k.__clz32 = t || function(e2) {
          var t2 = Math.LN2, i2 = Math.log;
          return 0 === e2 ? 32 : 0 | 31 - (0 | i2(e2 >>> 0) / t2);
        }, k.__imul = e || function(e2, t2) {
          return 0 | e2 * t2;
        }, k;
      });
    }
  });

  // node_modules/@gandlaf21/bc-ur/dist/lib/es6/xoshiro.js
  var import_jsbi, __spreadArrays, MAX_UINT64, rotl, Xoshiro, xoshiro_default;
  var init_xoshiro = __esm({
    "node_modules/@gandlaf21/bc-ur/dist/lib/es6/xoshiro.js"() {
      init_buffer_shim();
      init_utils2();
      init_bignumber();
      import_jsbi = __toESM(require_jsbi_umd());
      __spreadArrays = function() {
        for (var s = 0, i = 0, il = arguments.length; i < il; i++) s += arguments[i].length;
        for (var r = Array(s), k = 0, i = 0; i < il; i++)
          for (var a = arguments[i], j = 0, jl = a.length; j < jl; j++, k++)
            r[k] = a[j];
        return r;
      };
      MAX_UINT64 = 18446744073709552e3;
      rotl = function(x, k) {
        return import_jsbi.default.bitwiseXor(import_jsbi.default.asUintN(64, import_jsbi.default.leftShift(x, import_jsbi.default.BigInt(k))), import_jsbi.default.BigInt(import_jsbi.default.asUintN(64, import_jsbi.default.signedRightShift(x, import_jsbi.default.subtract(import_jsbi.default.BigInt(64), import_jsbi.default.BigInt(k))))));
      };
      Xoshiro = /** @class */
      (function() {
        function Xoshiro2(seed) {
          var _this = this;
          this.next = function() {
            return new bignumber_default(_this.roll().toString());
          };
          this.nextDouble = function() {
            return new bignumber_default(_this.roll().toString()).div(MAX_UINT64 + 1);
          };
          this.nextInt = function(low, high) {
            return Math.floor(_this.nextDouble().toNumber() * (high - low + 1) + low);
          };
          this.nextByte = function() {
            return _this.nextInt(0, 255);
          };
          this.nextData = function(count) {
            return __spreadArrays(new Array(count)).map(function() {
              return _this.nextByte();
            });
          };
          var digest = sha256Hash(seed);
          this.s = [import_jsbi.default.BigInt(0), import_jsbi.default.BigInt(0), import_jsbi.default.BigInt(0), import_jsbi.default.BigInt(0)];
          this.setS(digest);
        }
        Xoshiro2.prototype.setS = function(digest) {
          for (var i = 0; i < 4; i++) {
            var o = i * 8;
            var v = import_jsbi.default.BigInt(0);
            for (var n = 0; n < 8; n++) {
              v = import_jsbi.default.asUintN(64, import_jsbi.default.leftShift(v, import_jsbi.default.BigInt(8)));
              v = import_jsbi.default.asUintN(64, import_jsbi.default.bitwiseOr(v, import_jsbi.default.BigInt(digest[o + n])));
            }
            this.s[i] = import_jsbi.default.asUintN(64, v);
          }
        };
        Xoshiro2.prototype.roll = function() {
          var result = import_jsbi.default.asUintN(64, import_jsbi.default.multiply(rotl(import_jsbi.default.asUintN(64, import_jsbi.default.multiply(this.s[1], import_jsbi.default.BigInt(5))), 7), import_jsbi.default.BigInt(9)));
          var t = import_jsbi.default.asUintN(64, import_jsbi.default.leftShift(this.s[1], import_jsbi.default.BigInt(17)));
          this.s[2] = import_jsbi.default.asUintN(64, import_jsbi.default.bitwiseXor(this.s[2], import_jsbi.default.BigInt(this.s[0])));
          this.s[3] = import_jsbi.default.asUintN(64, import_jsbi.default.bitwiseXor(this.s[3], import_jsbi.default.BigInt(this.s[1])));
          this.s[1] = import_jsbi.default.asUintN(64, import_jsbi.default.bitwiseXor(this.s[1], import_jsbi.default.BigInt(this.s[2])));
          this.s[0] = import_jsbi.default.asUintN(64, import_jsbi.default.bitwiseXor(this.s[0], import_jsbi.default.BigInt(this.s[3])));
          this.s[2] = import_jsbi.default.asUintN(64, import_jsbi.default.bitwiseXor(this.s[2], import_jsbi.default.BigInt(t)));
          this.s[3] = import_jsbi.default.asUintN(64, rotl(this.s[3], 45));
          return result;
        };
        return Xoshiro2;
      })();
      xoshiro_default = Xoshiro;
    }
  });

  // node_modules/@apocentre/alias-sampling/index.js
  var require_alias_sampling = __commonJS({
    "node_modules/@apocentre/alias-sampling/index.js"(exports, module) {
      init_buffer_shim();
      function Sample(probabilities, outcomes, rng) {
        "use strict";
        this.alias = [];
        this.prob = [];
        this.outcomes = outcomes || this.indexedOutcomes(probabilities.length);
        this.rng = rng || Math.random;
        this.precomputeAlias(probabilities);
      }
      Sample.prototype.next = function(numOfSamples) {
        "use strict";
        var n = numOfSamples || 1, out = [], i = 0;
        do {
          var c = Math.floor(this.rng() * this.prob.length);
          out[i] = this.outcomes[this.rng() < this.prob[c] ? c : this.alias[c]];
        } while (++i < n);
        return n > 1 ? out : out[0];
      };
      Sample.prototype.precomputeAlias = function(p) {
        "use strict";
        var n = p.length, sum = 0, nS = 0, nL = 0, P = [], S = [], L = [], g, i, a;
        for (i = 0; i < n; ++i) {
          if (p[i] < 0) {
            throw "Probability must be a positive: p[" + i + "]=" + p[i];
          }
          sum += p[i];
        }
        if (sum === 0) {
          throw "Probability cannot be zero.";
        }
        for (i = 0; i < n; ++i) {
          P[i] = p[i] * n / sum;
        }
        for (i = n - 1; i >= 0; --i) {
          if (P[i] < 1)
            S[nS++] = i;
          else
            L[nL++] = i;
        }
        while (nS && nL) {
          a = S[--nS];
          g = L[--nL];
          this.prob[a] = P[a];
          this.alias[a] = g;
          P[g] = P[g] + P[a] - 1;
          if (P[g] < 1)
            S[nS++] = g;
          else
            L[nL++] = g;
        }
        while (nL)
          this.prob[L[--nL]] = 1;
        while (nS)
          this.prob[S[--nS]] = 1;
      };
      Sample.prototype.indexedOutcomes = function(n) {
        "use strict";
        var o = [];
        for (var i = 0; i < n; i++) o[i] = i;
        return o;
      };
      Sample.prototype.randomInt = function(min, max) {
        "use strict";
        return Math.floor(this.rng() * (max - min)) + min;
      };
      module.exports = function(probabilities, outcomes, rng) {
        "use strict";
        return new Sample(probabilities, outcomes, rng);
      };
    }
  });

  // node_modules/@gandlaf21/bc-ur/dist/lib/es6/fountainUtils.js
  var import_alias_sampling, import_buffer5, __spreadArrays2, chooseDegree, shuffle, chooseFragments;
  var init_fountainUtils = __esm({
    "node_modules/@gandlaf21/bc-ur/dist/lib/es6/fountainUtils.js"() {
      init_buffer_shim();
      init_utils2();
      init_xoshiro();
      import_alias_sampling = __toESM(require_alias_sampling());
      import_buffer5 = __toESM(require_buffer());
      __spreadArrays2 = function() {
        for (var s = 0, i = 0, il = arguments.length; i < il; i++) s += arguments[i].length;
        for (var r = Array(s), k = 0, i = 0; i < il; i++)
          for (var a = arguments[i], j = 0, jl = a.length; j < jl; j++, k++)
            r[k] = a[j];
        return r;
      };
      chooseDegree = function(seqLenth, rng) {
        var degreeProbabilities = __spreadArrays2(new Array(seqLenth)).map(function(_, index) {
          return 1 / (index + 1);
        });
        var degreeChooser = (0, import_alias_sampling.default)(degreeProbabilities, null, rng.nextDouble);
        return degreeChooser.next() + 1;
      };
      shuffle = function(items, rng) {
        var remaining = __spreadArrays2(items);
        var result = [];
        while (remaining.length > 0) {
          var index = rng.nextInt(0, remaining.length - 1);
          var item = remaining[index];
          remaining.splice(index, 1);
          result.push(item);
        }
        return result;
      };
      chooseFragments = function(seqNum, seqLength, checksum) {
        if (seqNum <= seqLength) {
          return [seqNum - 1];
        } else {
          var seed = import_buffer5.Buffer.concat([intToBytes(seqNum), intToBytes(checksum)]);
          var rng = new xoshiro_default(seed);
          var degree = chooseDegree(seqLength, rng);
          var indexes = __spreadArrays2(new Array(seqLength)).map(function(_, index) {
            return index;
          });
          var shuffledIndexes = shuffle(indexes, rng);
          return shuffledIndexes.slice(0, degree);
        }
      };
    }
  });

  // node_modules/@gandlaf21/bc-ur/dist/lib/es6/fountainEncoder.js
  var import_buffer6, FountainEncoderPart, FountainEncoder, fountainEncoder_default;
  var init_fountainEncoder = __esm({
    "node_modules/@gandlaf21/bc-ur/dist/lib/es6/fountainEncoder.js"() {
      init_buffer_shim();
      init_utils2();
      init_fountainUtils();
      init_cbor();
      import_buffer6 = __toESM(require_buffer());
      FountainEncoderPart = /** @class */
      (function() {
        function FountainEncoderPart2(_seqNum, _seqLength, _messageLength, _checksum, _fragment) {
          this._seqNum = _seqNum;
          this._seqLength = _seqLength;
          this._messageLength = _messageLength;
          this._checksum = _checksum;
          this._fragment = _fragment;
        }
        Object.defineProperty(FountainEncoderPart2.prototype, "messageLength", {
          get: function() {
            return this._messageLength;
          },
          enumerable: false,
          configurable: true
        });
        Object.defineProperty(FountainEncoderPart2.prototype, "fragment", {
          get: function() {
            return this._fragment;
          },
          enumerable: false,
          configurable: true
        });
        Object.defineProperty(FountainEncoderPart2.prototype, "seqNum", {
          get: function() {
            return this._seqNum;
          },
          enumerable: false,
          configurable: true
        });
        Object.defineProperty(FountainEncoderPart2.prototype, "seqLength", {
          get: function() {
            return this._seqLength;
          },
          enumerable: false,
          configurable: true
        });
        Object.defineProperty(FountainEncoderPart2.prototype, "checksum", {
          get: function() {
            return this._checksum;
          },
          enumerable: false,
          configurable: true
        });
        FountainEncoderPart2.prototype.cbor = function() {
          var result = cborEncode([
            this._seqNum,
            this._seqLength,
            this._messageLength,
            this._checksum,
            this._fragment
          ]);
          return import_buffer6.Buffer.from(result);
        };
        FountainEncoderPart2.prototype.description = function() {
          return "seqNum:" + this._seqNum + ", seqLen:" + this._seqLength + ", messageLen:" + this._messageLength + ", checksum:" + this._checksum + ", data:" + this._fragment.toString("hex");
        };
        FountainEncoderPart2.fromCBOR = function(cborPayload) {
          var _a = cborDecode(cborPayload), seqNum = _a[0], seqLength = _a[1], messageLength = _a[2], checksum = _a[3], fragment = _a[4];
          if (typeof seqNum !== "number" || typeof seqLength !== "number" || typeof messageLength !== "number" || typeof checksum !== "number" || import_buffer6.Buffer.isBuffer(fragment) && fragment.length <= 0) {
            throw new Error("type error");
          }
          return new FountainEncoderPart2(seqNum, seqLength, messageLength, checksum, import_buffer6.Buffer.from(fragment));
        };
        return FountainEncoderPart2;
      })();
      FountainEncoder = /** @class */
      (function() {
        function FountainEncoder2(message, maxFragmentLength, firstSeqNum, minFragmentLength) {
          if (maxFragmentLength === void 0) {
            maxFragmentLength = 100;
          }
          if (firstSeqNum === void 0) {
            firstSeqNum = 0;
          }
          if (minFragmentLength === void 0) {
            minFragmentLength = 10;
          }
          var fragmentLength = FountainEncoder2.findNominalFragmentLength(message.length, minFragmentLength, maxFragmentLength);
          this._messageLength = message.length;
          this._fragments = FountainEncoder2.partitionMessage(message, fragmentLength);
          this.fragmentLength = fragmentLength;
          this.seqNum = toUint32(firstSeqNum);
          this.checksum = getCRC(message);
        }
        Object.defineProperty(FountainEncoder2.prototype, "fragmentsLength", {
          get: function() {
            return this._fragments.length;
          },
          enumerable: false,
          configurable: true
        });
        Object.defineProperty(FountainEncoder2.prototype, "fragments", {
          get: function() {
            return this._fragments;
          },
          enumerable: false,
          configurable: true
        });
        Object.defineProperty(FountainEncoder2.prototype, "messageLength", {
          get: function() {
            return this._messageLength;
          },
          enumerable: false,
          configurable: true
        });
        FountainEncoder2.prototype.isComplete = function() {
          return this.seqNum >= this._fragments.length;
        };
        FountainEncoder2.prototype.isSinglePart = function() {
          return this._fragments.length === 1;
        };
        FountainEncoder2.prototype.seqLength = function() {
          return this._fragments.length;
        };
        FountainEncoder2.prototype.mix = function(indexes) {
          var _this = this;
          return indexes.reduce(function(result, index) {
            return bufferXOR(_this._fragments[index], result);
          }, import_buffer6.Buffer.alloc(this.fragmentLength, 0));
        };
        FountainEncoder2.prototype.nextPart = function() {
          this.seqNum = toUint32(this.seqNum + 1);
          var indexes = chooseFragments(this.seqNum, this._fragments.length, this.checksum);
          var mixed = this.mix(indexes);
          return new FountainEncoderPart(this.seqNum, this._fragments.length, this._messageLength, this.checksum, mixed);
        };
        FountainEncoder2.findNominalFragmentLength = function(messageLength, minFragmentLength, maxFragmentLength) {
          if (messageLength <= 0 || minFragmentLength <= 0 || maxFragmentLength < minFragmentLength) {
            throw new Error("invalid fragment or message length");
          }
          var maxFragmentCount = Math.ceil(messageLength / minFragmentLength);
          var fragmentLength = 0;
          for (var fragmentCount = 1; fragmentCount <= maxFragmentCount; fragmentCount++) {
            fragmentLength = Math.ceil(messageLength / fragmentCount);
            if (fragmentLength <= maxFragmentLength) {
              break;
            }
          }
          return fragmentLength;
        };
        FountainEncoder2.partitionMessage = function(message, fragmentLength) {
          var _a;
          var remaining = import_buffer6.Buffer.from(message);
          var fragment;
          var _fragments = [];
          while (remaining.length > 0) {
            _a = split(remaining, -fragmentLength), fragment = _a[0], remaining = _a[1];
            fragment = import_buffer6.Buffer.alloc(fragmentLength, 0).fill(fragment, 0, fragment.length);
            _fragments.push(fragment);
          }
          return _fragments;
        };
        return FountainEncoder2;
      })();
      fountainEncoder_default = FountainEncoder;
    }
  });

  // node_modules/@gandlaf21/bc-ur/dist/lib/es6/bytewords.js
  var import_buffer7, __spreadArrays3, bytewords, bytewordsLookUpTable, BYTEWORDS_NUM, BYTEWORD_LENGTH, MINIMAL_BYTEWORD_LENGTH, STYLES, getWord, getMinimalWord, addCRC, encodeWithSeparator, encodeMinimal, decodeWord, _decode, decode2, encode2, bytewords_default;
  var init_bytewords = __esm({
    "node_modules/@gandlaf21/bc-ur/dist/lib/es6/bytewords.js"() {
      init_buffer_shim();
      import_buffer7 = __toESM(require_buffer());
      init_utils2();
      __spreadArrays3 = function() {
        for (var s = 0, i = 0, il = arguments.length; i < il; i++) s += arguments[i].length;
        for (var r = Array(s), k = 0, i = 0; i < il; i++)
          for (var a = arguments[i], j = 0, jl = a.length; j < jl; j++, k++)
            r[k] = a[j];
        return r;
      };
      bytewords = "ableacidalsoapexaquaarchatomauntawayaxisbackbaldbarnbeltbetabiasbluebodybragbrewbulbbuzzcalmcashcatschefcityclawcodecolacookcostcruxcurlcuspcyandarkdatadaysdelidicedietdoordowndrawdropdrumdulldutyeacheasyechoedgeepicevenexamexiteyesfactfairfernfigsfilmfishfizzflapflewfluxfoxyfreefrogfuelfundgalagamegeargemsgiftgirlglowgoodgraygrimgurugushgyrohalfhanghardhawkheathelphighhillholyhopehornhutsicedideaidleinchinkyintoirisironitemjadejazzjoinjoltjowljudojugsjumpjunkjurykeepkenokeptkeyskickkilnkingkitekiwiknoblamblavalazyleaflegsliarlimplionlistlogoloudloveluaulucklungmainmanymathmazememomenumeowmildmintmissmonknailnavyneednewsnextnoonnotenumbobeyoboeomitonyxopenovalowlspaidpartpeckplaypluspoempoolposepuffpumapurrquadquizraceramprealredorichroadrockroofrubyruinrunsrustsafesagascarsetssilkskewslotsoapsolosongstubsurfswantacotasktaxitenttiedtimetinytoiltombtoystriptunatwinuglyundouniturgeuservastveryvetovialvibeviewvisavoidvowswallwandwarmwaspwavewaxywebswhatwhenwhizwolfworkyankyawnyellyogayurtzapszerozestzinczonezoom";
      bytewordsLookUpTable = [];
      BYTEWORDS_NUM = 256;
      BYTEWORD_LENGTH = 4;
      MINIMAL_BYTEWORD_LENGTH = 2;
      (function(STYLES2) {
        STYLES2["STANDARD"] = "standard";
        STYLES2["URI"] = "uri";
        STYLES2["MINIMAL"] = "minimal";
      })(STYLES || (STYLES = {}));
      getWord = function(index) {
        return bytewords.slice(index * BYTEWORD_LENGTH, index * BYTEWORD_LENGTH + BYTEWORD_LENGTH);
      };
      getMinimalWord = function(index) {
        var byteword = getWord(index);
        return "" + byteword[0] + byteword[BYTEWORD_LENGTH - 1];
      };
      addCRC = function(string) {
        var crc = getCRCHex(import_buffer7.Buffer.from(string, "hex"));
        return "" + string + crc;
      };
      encodeWithSeparator = function(word, separator) {
        var crcAppendedWord = addCRC(word);
        var crcWordBuff = import_buffer7.Buffer.from(crcAppendedWord, "hex");
        var result = crcWordBuff.reduce(function(result2, w) {
          return __spreadArrays3(result2, [getWord(w)]);
        }, []);
        return result.join(separator);
      };
      encodeMinimal = function(word) {
        var crcAppendedWord = addCRC(word);
        var crcWordBuff = import_buffer7.Buffer.from(crcAppendedWord, "hex");
        var result = crcWordBuff.reduce(function(result2, w) {
          return result2 + getMinimalWord(w);
        }, "");
        return result;
      };
      decodeWord = function(word, wordLength) {
        if (word.length !== wordLength) {
          throw new Error("'Invalid Bytewords: word.length does not match wordLength provided'");
        }
        var dim = 26;
        if (bytewordsLookUpTable.length === 0) {
          var array_len = dim * dim;
          bytewordsLookUpTable = __spreadArrays3(new Array(array_len)).map(function() {
            return -1;
          });
          for (var i = 0; i < BYTEWORDS_NUM; i++) {
            var byteword = getWord(i);
            var x_1 = byteword[0].charCodeAt(0) - "a".charCodeAt(0);
            var y_1 = byteword[3].charCodeAt(0) - "a".charCodeAt(0);
            var offset_1 = y_1 * dim + x_1;
            bytewordsLookUpTable[offset_1] = i;
          }
        }
        var x = word[0].toLowerCase().charCodeAt(0) - "a".charCodeAt(0);
        var y = word[wordLength == 4 ? 3 : 1].toLowerCase().charCodeAt(0) - "a".charCodeAt(0);
        if (!(0 <= x && x < dim && 0 <= y && y < dim)) {
          throw new Error("Invalid Bytewords: invalid word");
        }
        var offset = y * dim + x;
        var value = bytewordsLookUpTable[offset];
        if (value === -1) {
          throw new Error("Invalid Bytewords: value not in lookup table");
        }
        if (wordLength == BYTEWORD_LENGTH) {
          var byteword = getWord(value);
          var c1 = word[1].toLowerCase();
          var c2 = word[2].toLowerCase();
          if (!(c1 === byteword[1] && c2 === byteword[2])) {
            throw new Error("Invalid Bytewords: invalid middle letters of word");
          }
        }
        return import_buffer7.Buffer.from([value]).toString("hex");
      };
      _decode = function(string, separator, wordLength) {
        var words = wordLength == BYTEWORD_LENGTH ? string.split(separator) : partition(string, 2);
        var decodedString = words.map(function(word) {
          return decodeWord(word, wordLength);
        }).join("");
        if (decodedString.length < 5) {
          throw new Error("Invalid Bytewords: invalid decoded string length");
        }
        var _a = split(import_buffer7.Buffer.from(decodedString, "hex"), 4), body = _a[0], bodyChecksum = _a[1];
        var checksum = getCRCHex(body);
        if (checksum !== bodyChecksum.toString("hex")) {
          throw new Error("Invalid Checksum");
        }
        return body.toString("hex");
      };
      decode2 = function(string, style) {
        if (style === void 0) {
          style = STYLES.MINIMAL;
        }
        switch (style) {
          case STYLES.STANDARD:
            return _decode(string, " ", BYTEWORD_LENGTH);
          case STYLES.URI:
            return _decode(string, "-", BYTEWORD_LENGTH);
          case STYLES.MINIMAL:
            return _decode(string, "", MINIMAL_BYTEWORD_LENGTH);
          default:
            throw new Error("Invalid style " + style);
        }
      };
      encode2 = function(string, style) {
        if (style === void 0) {
          style = STYLES.MINIMAL;
        }
        switch (style) {
          case STYLES.STANDARD:
            return encodeWithSeparator(string, " ");
          case STYLES.URI:
            return encodeWithSeparator(string, "-");
          case STYLES.MINIMAL:
            return encodeMinimal(string);
          default:
            throw new Error("Invalid style " + style);
        }
      };
      bytewords_default = {
        decode: decode2,
        encode: encode2,
        STYLES
      };
    }
  });

  // node_modules/@gandlaf21/bc-ur/dist/lib/es6/urEncoder.js
  var __spreadArrays4, UREncoder, urEncoder_default;
  var init_urEncoder = __esm({
    "node_modules/@gandlaf21/bc-ur/dist/lib/es6/urEncoder.js"() {
      init_buffer_shim();
      init_fountainEncoder();
      init_bytewords();
      __spreadArrays4 = function() {
        for (var s = 0, i = 0, il = arguments.length; i < il; i++) s += arguments[i].length;
        for (var r = Array(s), k = 0, i = 0; i < il; i++)
          for (var a = arguments[i], j = 0, jl = a.length; j < jl; j++, k++)
            r[k] = a[j];
        return r;
      };
      UREncoder = /** @class */
      (function() {
        function UREncoder2(_ur, maxFragmentLength, firstSeqNum, minFragmentLength) {
          this.ur = _ur;
          this.fountainEncoder = new fountainEncoder_default(_ur.cbor, maxFragmentLength, firstSeqNum, minFragmentLength);
        }
        Object.defineProperty(UREncoder2.prototype, "fragmentsLength", {
          get: function() {
            return this.fountainEncoder.fragmentsLength;
          },
          enumerable: false,
          configurable: true
        });
        Object.defineProperty(UREncoder2.prototype, "fragments", {
          get: function() {
            return this.fountainEncoder.fragments;
          },
          enumerable: false,
          configurable: true
        });
        Object.defineProperty(UREncoder2.prototype, "messageLength", {
          get: function() {
            return this.fountainEncoder.messageLength;
          },
          enumerable: false,
          configurable: true
        });
        Object.defineProperty(UREncoder2.prototype, "cbor", {
          get: function() {
            return this.ur.cbor;
          },
          enumerable: false,
          configurable: true
        });
        UREncoder2.prototype.encodeWhole = function() {
          var _this = this;
          return __spreadArrays4(new Array(this.fragmentsLength)).map(function() {
            return _this.nextPart();
          });
        };
        UREncoder2.prototype.nextPart = function() {
          var part = this.fountainEncoder.nextPart();
          if (this.fountainEncoder.isSinglePart()) {
            return UREncoder2.encodeSinglePart(this.ur);
          } else {
            return UREncoder2.encodePart(this.ur.type, part);
          }
        };
        UREncoder2.encodeUri = function(scheme, pathComponents) {
          var path = pathComponents.join("/");
          return [scheme, path].join(":");
        };
        UREncoder2.encodeUR = function(pathComponents) {
          return UREncoder2.encodeUri("ur", pathComponents);
        };
        UREncoder2.encodePart = function(type, part) {
          var seq = part.seqNum + "-" + part.seqLength;
          var body = bytewords_default.encode(part.cbor().toString("hex"), bytewords_default.STYLES.MINIMAL);
          return UREncoder2.encodeUR([type, seq, body]);
        };
        UREncoder2.encodeSinglePart = function(ur) {
          var body = bytewords_default.encode(ur.cbor.toString("hex"), bytewords_default.STYLES.MINIMAL);
          return UREncoder2.encodeUR([ur.type, body]);
        };
        return UREncoder2;
      })();
      urEncoder_default = UREncoder;
    }
  });

  // node_modules/@gandlaf21/bc-ur/dist/lib/es6/fountainDecoder.js
  var import_buffer8, __spreadArrays5, FountainDecoderPart, FountainDecoder, fountainDecoder_default;
  var init_fountainDecoder = __esm({
    "node_modules/@gandlaf21/bc-ur/dist/lib/es6/fountainDecoder.js"() {
      init_buffer_shim();
      init_utils2();
      init_fountainUtils();
      init_errors();
      import_buffer8 = __toESM(require_buffer());
      __spreadArrays5 = function() {
        for (var s = 0, i = 0, il = arguments.length; i < il; i++) s += arguments[i].length;
        for (var r = Array(s), k = 0, i = 0; i < il; i++)
          for (var a = arguments[i], j = 0, jl = a.length; j < jl; j++, k++)
            r[k] = a[j];
        return r;
      };
      FountainDecoderPart = /** @class */
      (function() {
        function FountainDecoderPart2(_indexes, _fragment) {
          this._indexes = _indexes;
          this._fragment = _fragment;
        }
        Object.defineProperty(FountainDecoderPart2.prototype, "indexes", {
          get: function() {
            return this._indexes;
          },
          enumerable: false,
          configurable: true
        });
        Object.defineProperty(FountainDecoderPart2.prototype, "fragment", {
          get: function() {
            return this._fragment;
          },
          enumerable: false,
          configurable: true
        });
        FountainDecoderPart2.fromEncoderPart = function(encoderPart) {
          var indexes = chooseFragments(encoderPart.seqNum, encoderPart.seqLength, encoderPart.checksum);
          var fragment = encoderPart.fragment;
          return new FountainDecoderPart2(indexes, fragment);
        };
        FountainDecoderPart2.prototype.isSimple = function() {
          return this.indexes.length === 1;
        };
        return FountainDecoderPart2;
      })();
      FountainDecoder = /** @class */
      (function() {
        function FountainDecoder2() {
          this.result = void 0;
          this.expectedMessageLength = 0;
          this.expectedChecksum = 0;
          this.expectedFragmentLength = 0;
          this.processedPartsCount = 0;
          this.expectedPartIndexes = [];
          this.lastPartIndexes = [];
          this.queuedParts = [];
          this.receivedPartIndexes = [];
          this.mixedParts = [];
          this.simpleParts = [];
        }
        FountainDecoder2.prototype.validatePart = function(part) {
          var _this = this;
          if (this.expectedPartIndexes.length === 0) {
            __spreadArrays5(new Array(part.seqLength)).forEach(function(_, index) {
              return _this.expectedPartIndexes.push(index);
            });
            this.expectedMessageLength = part.messageLength;
            this.expectedChecksum = part.checksum;
            this.expectedFragmentLength = part.fragment.length;
          } else {
            if (this.expectedPartIndexes.length !== part.seqLength) {
              return false;
            }
            if (this.expectedMessageLength !== part.messageLength) {
              return false;
            }
            if (this.expectedChecksum !== part.checksum) {
              return false;
            }
            if (this.expectedFragmentLength !== part.fragment.length) {
              return false;
            }
          }
          return true;
        };
        FountainDecoder2.prototype.reducePartByPart = function(a, b) {
          if (arrayContains(a.indexes, b.indexes)) {
            var newIndexes = setDifference(a.indexes, b.indexes);
            var newFragment = bufferXOR(a.fragment, b.fragment);
            return new FountainDecoderPart(newIndexes, newFragment);
          } else {
            return a;
          }
        };
        FountainDecoder2.prototype.reduceMixedBy = function(part) {
          var _this = this;
          var newMixed = [];
          this.mixedParts.map(function(_a) {
            var mixedPart = _a.value;
            return _this.reducePartByPart(mixedPart, part);
          }).forEach(function(reducedPart) {
            if (reducedPart.isSimple()) {
              _this.queuedParts.push(reducedPart);
            } else {
              newMixed.push({ key: reducedPart.indexes, value: reducedPart });
            }
          });
          this.mixedParts = newMixed;
        };
        FountainDecoder2.prototype.processSimplePart = function(part) {
          var fragmentIndex = part.indexes[0];
          if (this.receivedPartIndexes.includes(fragmentIndex)) {
            return;
          }
          this.simpleParts.push({ key: part.indexes, value: part });
          this.receivedPartIndexes.push(fragmentIndex);
          if (arraysEqual(this.receivedPartIndexes, this.expectedPartIndexes)) {
            var sortedParts = this.simpleParts.map(function(_a) {
              var value = _a.value;
              return value;
            }).sort(function(a, b) {
              return a.indexes[0] - b.indexes[0];
            });
            var message = FountainDecoder2.joinFragments(sortedParts.map(function(part2) {
              return part2.fragment;
            }), this.expectedMessageLength);
            var checksum = getCRC(message);
            if (checksum === this.expectedChecksum) {
              this.result = message;
            } else {
              this.error = new InvalidChecksumError();
            }
          } else {
            this.reduceMixedBy(part);
          }
        };
        FountainDecoder2.prototype.processMixedPart = function(part) {
          var _this = this;
          if (this.mixedParts.some(function(_a) {
            var indexes = _a.key;
            return arraysEqual(indexes, part.indexes);
          })) {
            return;
          }
          var p2 = this.simpleParts.reduce(function(acc, _a) {
            var p = _a.value;
            return _this.reducePartByPart(acc, p);
          }, part);
          p2 = this.mixedParts.reduce(function(acc, _a) {
            var p = _a.value;
            return _this.reducePartByPart(acc, p);
          }, p2);
          if (p2.isSimple()) {
            this.queuedParts.push(p2);
          } else {
            this.reduceMixedBy(p2);
            this.mixedParts.push({ key: p2.indexes, value: p2 });
          }
        };
        FountainDecoder2.prototype.processQueuedItem = function() {
          if (this.queuedParts.length === 0) {
            return;
          }
          var part = this.queuedParts.shift();
          if (part.isSimple()) {
            this.processSimplePart(part);
          } else {
            this.processMixedPart(part);
          }
        };
        FountainDecoder2.prototype.receivePart = function(encoderPart) {
          if (this.isComplete()) {
            return false;
          }
          if (!this.validatePart(encoderPart)) {
            return false;
          }
          var decoderPart = FountainDecoderPart.fromEncoderPart(encoderPart);
          this.lastPartIndexes = decoderPart.indexes;
          this.queuedParts.push(decoderPart);
          while (!this.isComplete() && this.queuedParts.length > 0) {
            this.processQueuedItem();
          }
          ;
          this.processedPartsCount += 1;
          return true;
        };
        FountainDecoder2.prototype.isComplete = function() {
          return Boolean(this.result !== void 0 && this.result.length > 0);
        };
        FountainDecoder2.prototype.isSuccess = function() {
          return Boolean(this.error === void 0 && this.isComplete());
        };
        FountainDecoder2.prototype.resultMessage = function() {
          return this.isSuccess() ? this.result : import_buffer8.Buffer.from([]);
        };
        FountainDecoder2.prototype.isFailure = function() {
          return this.error !== void 0;
        };
        FountainDecoder2.prototype.resultError = function() {
          return this.error ? this.error.message : "";
        };
        FountainDecoder2.prototype.expectedPartCount = function() {
          return this.expectedPartIndexes.length;
        };
        FountainDecoder2.prototype.getExpectedPartIndexes = function() {
          return __spreadArrays5(this.expectedPartIndexes);
        };
        FountainDecoder2.prototype.getReceivedPartIndexes = function() {
          return __spreadArrays5(this.receivedPartIndexes);
        };
        FountainDecoder2.prototype.getLastPartIndexes = function() {
          return __spreadArrays5(this.lastPartIndexes);
        };
        FountainDecoder2.prototype.estimatedPercentComplete = function() {
          if (this.isComplete()) {
            return 1;
          }
          var expectedPartCount = this.expectedPartCount();
          if (expectedPartCount === 0) {
            return 0;
          }
          return Math.min(0.99, this.processedPartsCount / (expectedPartCount * 1.75));
        };
        FountainDecoder2.prototype.getProgress = function() {
          if (this.isComplete()) {
            return 1;
          }
          var expectedPartCount = this.expectedPartCount();
          if (expectedPartCount === 0) {
            return 0;
          }
          return this.receivedPartIndexes.length / expectedPartCount;
        };
        FountainDecoder2.joinFragments = function(fragments, messageLength) {
          return import_buffer8.Buffer.concat(fragments).slice(0, messageLength);
        };
        return FountainDecoder2;
      })();
      fountainDecoder_default = FountainDecoder;
    }
  });

  // node_modules/@gandlaf21/bc-ur/dist/lib/es6/urDecoder.js
  var import_buffer9, URDecoder;
  var init_urDecoder = __esm({
    "node_modules/@gandlaf21/bc-ur/dist/lib/es6/urDecoder.js"() {
      init_buffer_shim();
      init_fountainDecoder();
      init_bytewords();
      init_utils2();
      init_errors();
      init_ur();
      init_fountainEncoder();
      import_buffer9 = __toESM(require_buffer());
      URDecoder = /** @class */
      (function() {
        function URDecoder2(fountainDecoder, type) {
          if (fountainDecoder === void 0) {
            fountainDecoder = new fountainDecoder_default();
          }
          if (type === void 0) {
            type = "bytes";
          }
          this.fountainDecoder = fountainDecoder;
          this.type = type;
          if (!isURType(type)) {
            throw new Error("Invalid UR type");
          }
          this.expected_type = "";
        }
        URDecoder2.decodeBody = function(type, message) {
          var cbor = bytewords_default.decode(message, bytewords_default.STYLES.MINIMAL);
          return new ur_default(import_buffer9.Buffer.from(cbor, "hex"), type);
        };
        URDecoder2.prototype.validatePart = function(type) {
          if (this.expected_type) {
            return this.expected_type === type;
          }
          if (!isURType(type)) {
            return false;
          }
          this.expected_type = type;
          return true;
        };
        URDecoder2.decode = function(message) {
          var _a = this.parse(message), type = _a[0], components = _a[1];
          if (components.length === 0) {
            throw new InvalidPathLengthError();
          }
          var body = components[0];
          return URDecoder2.decodeBody(type, body);
        };
        URDecoder2.parse = function(message) {
          var lowercase = message.toLowerCase();
          var prefix = lowercase.slice(0, 3);
          if (prefix !== "ur:") {
            throw new InvalidSchemeError();
          }
          var components = lowercase.slice(3).split("/");
          var type = components[0];
          if (components.length < 2) {
            throw new InvalidPathLengthError();
          }
          if (!isURType(type)) {
            throw new InvalidTypeError();
          }
          return [type, components.slice(1)];
        };
        URDecoder2.parseSequenceComponent = function(s) {
          var components = s.split("-");
          if (components.length !== 2) {
            throw new InvalidSequenceComponentError();
          }
          var seqNum = toUint32(Number(components[0]));
          var seqLength = Number(components[1]);
          if (seqNum < 1 || seqLength < 1) {
            throw new InvalidSequenceComponentError();
          }
          return [seqNum, seqLength];
        };
        URDecoder2.prototype.receivePart = function(s) {
          if (this.result !== void 0) {
            return false;
          }
          var _a = URDecoder2.parse(s), type = _a[0], components = _a[1];
          if (!this.validatePart(type)) {
            return false;
          }
          if (components.length === 1) {
            this.result = URDecoder2.decodeBody(type, components[0]);
            return true;
          }
          if (components.length !== 2) {
            throw new InvalidPathLengthError();
          }
          var seq = components[0], fragment = components[1];
          var _b = URDecoder2.parseSequenceComponent(seq), seqNum = _b[0], seqLength = _b[1];
          var cbor = bytewords_default.decode(fragment, bytewords_default.STYLES.MINIMAL);
          var part = FountainEncoderPart.fromCBOR(cbor);
          if (seqNum !== part.seqNum || seqLength !== part.seqLength) {
            return false;
          }
          if (!this.fountainDecoder.receivePart(part)) {
            return false;
          }
          if (this.fountainDecoder.isSuccess()) {
            this.result = new ur_default(this.fountainDecoder.resultMessage(), type);
          } else if (this.fountainDecoder.isFailure()) {
            this.error = new InvalidSchemeError();
          }
          return true;
        };
        URDecoder2.prototype.resultUR = function() {
          return this.result ? this.result : new ur_default(import_buffer9.Buffer.from([]));
        };
        URDecoder2.prototype.isComplete = function() {
          return this.result && this.result.cbor.length > 0 ? true : false;
        };
        URDecoder2.prototype.isSuccess = function() {
          return !this.error && this.isComplete();
        };
        URDecoder2.prototype.isError = function() {
          return this.error !== void 0;
        };
        URDecoder2.prototype.resultError = function() {
          return this.error ? this.error.message : "";
        };
        URDecoder2.prototype.expectedPartCount = function() {
          return this.fountainDecoder.expectedPartCount();
        };
        URDecoder2.prototype.expectedPartIndexes = function() {
          return this.fountainDecoder.getExpectedPartIndexes();
        };
        URDecoder2.prototype.receivedPartIndexes = function() {
          return this.fountainDecoder.getReceivedPartIndexes();
        };
        URDecoder2.prototype.lastPartIndexes = function() {
          return this.fountainDecoder.getLastPartIndexes();
        };
        URDecoder2.prototype.estimatedPercentComplete = function() {
          return this.fountainDecoder.estimatedPercentComplete();
        };
        URDecoder2.prototype.getProgress = function() {
          return this.fountainDecoder.getProgress();
        };
        return URDecoder2;
      })();
    }
  });

  // node_modules/@gandlaf21/bc-ur/dist/lib/es6/index.js
  var init_es6 = __esm({
    "node_modules/@gandlaf21/bc-ur/dist/lib/es6/index.js"() {
      init_buffer_shim();
      init_ur();
      init_urEncoder();
      init_urDecoder();
    }
  });

  // entry.js
  var require_entry = __commonJS({
    "entry.js"() {
      init_buffer_shim();
      init_es6();
      window.bcur = { UR: ur_default, UREncoder: urEncoder_default };
    }
  });
  require_entry();
})();
