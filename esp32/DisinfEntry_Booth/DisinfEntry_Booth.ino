/*
  Smart Disinfectant - Automatic Sprayer with Automatic Door
  Board: ESP32

  Detection: HC-SR04 ultrasonic distance sensor (50cm range)
  Safety: MLX90614 infrared temperature sensor
    - If detected person's temperature is >= the fever threshold, the pump and
      door sequence is BLOCKED (access denied, door stays closed).
    - System waits until the person leaves range before resetting.

  Sync: posts cycles and telemetry to the DisinfEntry server
    POST <server>/api/booth_sync.php   with the X-API-Key header

    Two ways to reach the server, chosen by SERVER_URL_VALUE in secrets.h:

      - SERVER_URL_VALUE set (e.g. "https://your-app.up.railway.app"): a fixed
        address, over TLS when it starts with https://. No searching.
      - SERVER_URL_VALUE not set: a XAMPP machine on the local network. The
        server is located at run time - by the address that worked last time,
        then by mDNS name, then by sweeping the subnet - so a new DHCP lease
        does not mean reflashing. See SERVER_HOSTNAME below.

    The server records each cycle, publishes it to the entry log, and answers
    with the settings the booth should adopt - so the threshold and the spray
    timings can be retuned from System Settings without reflashing.

    Syncing only ever happens while the booth is IDLE with nobody in range, so
    a slow network can never stretch a spray burst or hold the door open.

  IMPORTANT WIRING NOTE:
  HC-SR04 ECHO pin outputs 5V. ESP32 GPIOs are 3.3V only.
  You MUST use a voltage divider (e.g. 1k + 2k resistors) or a
  logic level shifter on the ECHO line, or you can damage the pin.

  Libraries required:
   - ESP32Servo
   - Adafruit MLX90614 (Library Manager: "Adafruit MLX90614")
  (WiFi and HTTPClient ship with the ESP32 board package. The payloads are
   assembled by hand rather than with ArduinoJson: every value is a number or
   a literal this sketch controls, so a JSON library would only add a
   dependency and a v6/v7 API to get wrong.)
*/

#include <WiFi.h>
#include <HTTPClient.h>
#include <WiFiClientSecure.h>   // TLS, for an https:// SERVER_URL
#include <ESPmDNS.h>     // resolve the server by name, so a new DHCP lease costs nothing
#include <Preferences.h>  // remember the address that worked, across reboots
#include <esp_system.h>   // esp_random(), for the boot id
#include <ESP32Servo.h>
#include <Wire.h>
#include <Adafruit_MLX90614.h>

// ---------- Secrets: kept out of the sketch so they never reach version control ----------
// secrets.h defines WIFI_SSID, WIFI_PASSWORD, SERVER_HOSTNAME and API_KEY. It is
// git-ignored. First time: copy secrets.example.h to secrets.h (same folder as this
// file) and fill it in.
#include "secrets.h"

// ---------- Fixed server (Railway, or any hosted site) ----------
// Optional. Define SERVER_URL_VALUE in secrets.h to talk to a fixed address instead of
// searching the local network:
//     #define SERVER_URL_VALUE "https://your-app.up.railway.app"   // no trailing slash
// With https:// the connection uses TLS. Leave it undefined for a local XAMPP server;
// the discovery logic below is then used exactly as before.
#ifndef SERVER_URL_VALUE
  #define SERVER_URL_VALUE ""
#endif
const char* SERVER_URL = SERVER_URL_VALUE;

// A TLS handshake takes a second or two, so https gets more time than plain http.
// Still bounded: syncing only runs while the booth is idle with nobody in range.
const uint16_t HTTPS_TIMEOUT_MS = 8000;
// (The helper functions for this live below the type definitions - see
// usingFixedServer(). The Arduino build puts generated prototypes before the first
// function in the file, so functions must not come before the enums they depend on.)

// ---------- Where the server is: found, not hardcoded ----------
// The XAMPP machine holds a DHCP lease, so its address changes on its own. The
// booth therefore looks the server up rather than carrying an IP that goes
// stale. It tries three things in order, until one answers /api/ping.php with
// the DisinfEntry signature:
//
//   1. the address that worked last time (kept in flash across reboots, and
//      discarded on sight if the booth has since joined a different network)
//   2. SERVER_HOSTNAME over mDNS - the Windows computer name WITHOUT ".local".
//      Find it by running `hostname` in cmd on the XAMPP machine; Windows
//      answers mDNS for its own name out of the box, nothing to install.
//   3. a sweep of the local subnet, a slice per pass so nothing blocks
//
// Whatever answers is written to flash, so the search happens once and not on
// every boot. Set SERVER_PIN_IP to force one address and skip all of it.
// SERVER_HOSTNAME is set in secrets.h.
const char* SERVER_PATH     = "/DisinfEntry";   // no trailing slash
const char* SERVER_PIN_IP   = "";               // e.g. "192.168.1.49"; "" = discover
const uint16_t SERVER_PORT  = 80;

// API_KEY (in secrets.h) must match System Settings -> Booth API Access.

const char* DEVICE_ID    = "ESP32-BOOTH-01";
const char* DEVICE_NAME   = "Main Gate Booth";
const char* FIRMWARE      = "1.1.0";

// ---------- Pin configuration ----------
const int TRIG_PIN   = 5;
const int ECHO_PIN   = 18;   // through voltage divider!
const int RELAY_PIN  = 26;
const int SERVO_PIN  = 25;
const int I2C_SDA    = 21;
const int I2C_SCL    = 22;

const bool RELAY_ACTIVE_LOW = true;

const int DOOR_CLOSED_ANGLE = 0;
const int DOOR_OPEN_ANGLE   = 120;

const int SERVO_MIN_US = 500;
const int SERVO_MAX_US = 2500;

// ---------- Fixed detection settings ----------
const unsigned long ULTRASONIC_TIMEOUT_US = 25000;

// ---------- Fixed timing (all in milliseconds) ----------
const unsigned long PRESENCE_GLITCH_GRACE = 500;

// How much longer than door_open_time_ms the door may stay open while someone
// is still standing in the doorway. The configured hold is a minimum, not a
// deadline - but something parked in front of the sensor must not wedge the
// door open for good, so the wait is bounded.
const unsigned long DOOR_CLEAR_GRACE = 30000;
const unsigned long SAMPLE_INTERVAL       = 100;   // how often to actually ping the sensor (keep fast for responsiveness)
const unsigned long PRINT_INTERVAL        = 1000;  // how often to print/record distance (slowed down)
const unsigned long TEMP_PRINT_INTERVAL   = 2000;  // how often to print/record temperature

// How often to re-attempt the thermometer while it is missing. begin() used to
// run once in setup(), so a loose I2C wire left the booth denying everyone
// until somebody power-cycled it - the one failure that stops the booth doing
// its job, and the one that needed a human to notice.
const unsigned long MLX_RETRY_INTERVAL    = 10000;

// ---------- Server-adjustable settings ----------
// These start at the values the sketch was flashed with and are replaced by
// whatever the server returns, provided the value is inside a sane range.
// Everything above this line is compiled in and can only change by reflashing.
float         detectionDistanceCm = 50.0;
float         feverThresholdC     = 37.8;
unsigned long presenceConfirmTime = 5000;
unsigned long pumpOnTime          = 2000;
unsigned long pumpOffTime         = 2000;
unsigned long doorOpenTime        = 5000;

// ---------- Sync behaviour ----------
const unsigned long HEARTBEAT_INTERVAL  = 30000;  // sync at least this often
const unsigned long MIN_SYNC_GAP        = 3000;   // never retry faster than this
const unsigned long WIFI_RETRY_INTERVAL = 15000;
const uint16_t      HTTP_TIMEOUT_MS     = 3000;

// ---------- Server discovery ----------
// Discovery runs only while the booth is IDLE with nobody in range, but it must
// still hand control back promptly: someone can walk up at any moment, and a
// booth that ignores them for seconds is a broken booth.
//
// Two rules keep that honest, because counting probes does not. A probe costs
// PROBE_CONNECT_MS against a dead address, but PROBE_CONNECT_MS +
// PROBE_READ_MS against a host that accepts the connection and then dawdles -
// and a lab network has plenty of those:
//
//   1. one phase per pass (remembered / mDNS / sweep), never all three
//   2. DISCOVERY_BUDGET_MS is checked before each probe, so the wall clock
//      bounds a pass rather than an assumed per-probe cost
//
// Worst case is therefore the budget plus the one probe already in flight.
//
// Two timeout pairs, because the two kinds of probe want opposite things.
// Sweeping 254 addresses needs a dead one to fail FAST or a lap takes forever.
// Probing an address we have reason to believe in - the remembered one, or the
// one mDNS just named - needs to WAIT, because 150 ms is under the round trip
// of a busy 2.4 GHz link and a laptop whose wifi radio is power-saving. Using
// the sweep's timeout for both is what made a live server look dead.
const uint16_t      PROBE_CONNECT_MS  = 150;    // sweep: reject dead addresses quickly
const uint16_t      PROBE_READ_MS     = 350;
const uint16_t      TARGET_CONNECT_MS = 1200;   // an address expected to answer
const uint16_t      TARGET_READ_MS    = 1500;
const unsigned long DISCOVERY_BUDGET_MS = 1500; // hard cap on one pass
const uint32_t      MDNS_QUERY_MS     = 1500;   // mdns_query_a blocks this long on a miss

// Consecutive connection-level failures before the booth stops trusting the
// address it is using. Generous on purpose: a couple of dropped POSTs is a wifi
// hiccup, not a server that moved, and throwing the address away over one turns
// a three-second recovery into a subnet sweep.
const uint8_t       LOST_AFTER_FAILS  = 10;

const size_t MAX_PENDING_CYCLES = 5;
const size_t MAX_CYCLE_STATES   = 12;
const size_t DISTANCE_BUFFER    = 40;
const size_t TEMP_BUFFER        = 20;

Servo doorServo;
Adafruit_MLX90614 mlx = Adafruit_MLX90614();

enum SystemState {
  IDLE, CONFIRMING,
  PUMP_ON_1, PUMP_OFF_INTERVAL, PUMP_ON_2,
  DOOR_OPENING, DOOR_HOLD_OPEN, DOOR_CLOSING,
  ACCESS_DENIED
};

SystemState currentState = IDLE;
unsigned long stateStartTime = 0;
unsigned long motionStartTime = 0;
unsigned long lastOutOfRangeTime = 0;
unsigned long lastSampleTime = 0;
unsigned long lastPrintTime = 0;
unsigned long lastTempPrint = 0;
bool currentlyInRange = false;
float lastDistance = -1;

// ---------- Sync state ----------
String bootId;                    // new on every reset; scopes every uptime_ms
bool   mlxOk = true;
unsigned long lastMlxRetry = 0;   // paced re-attempts while the thermometer is missing
bool   bootReported = false;      // send the "boot" section until one sync lands
bool   configDirty = true;        // send the "config" section when it changed
unsigned long lastSyncAttempt = 0;
unsigned long lastSyncOk = 0;
uint32_t droppedSamples = 0;
uint32_t droppedCycles = 0;

// ---------- Server discovery state ----------
// One phase runs per pass, in this order, cycling back to mDNS after a full
// sweep lap - a lease that appeared while the booth was looking is far cheaper
// to find by name than by walking 254 addresses again.
enum DiscoveryPhase { DISC_REMEMBERED, DISC_MDNS, DISC_SWEEP };

Preferences     prefs;                 // NVS: survives a reset, so the search runs once
String          serverHost;            // resolved address in use; empty = still looking
uint32_t        serverNet = 0;         // network it was found on, to spot a move
DiscoveryPhase  discPhase = DISC_REMEMBERED;
uint16_t        scanOctet = 1;         // sweep position, so a pass resumes where it stopped
uint8_t         connFailures = 0;

// ---------- Fixed-server helpers (see SERVER_URL_VALUE in secrets.h) ----------
bool usingFixedServer() { return SERVER_URL[0] != '\0'; }
bool serverIsHttps()    { return strncmp(SERVER_URL, "https://", 8) == 0; }

/**
 * Prepares a TLS client.
 *
 * KNOWN LIMITATION: this encrypts the connection but does NOT verify the server's
 * certificate (setInsecure). Verifying needs the right clock - the ESP32 has none until
 * it is given NTP - and a CA certificate to compare against; neither is set up here. The
 * practical risk is someone actively impersonating your site on the booth's network and
 * reading the API key, which can be regenerated in System Settings. Treat the key as
 * disposable, and do not reuse it anywhere else.
 */
void configureTls(WiFiClientSecure& client) {
  static bool warned = false;
  if (!warned) {
    warned = true;
    Serial.println("!! TLS: encrypted, but the server certificate is not verified (see configureTls).");
  }
  client.setInsecure();
}
bool            mdnsStarted = false;

// ---------- Cycle in progress ----------
struct StateRecord {
  SystemState from;
  SystemState to;
  uint32_t    uptime;
};

bool          cycleActive = false;
uint32_t      cycleDetectedUptime = 0;
float         cycleTriggerDistance = NAN;
float         cycleScreeningTemp = NAN;
float         cycleAmbientTemp = NAN;
float         cycleThreshold = 37.8;
uint8_t       cyclePumpBursts = 0;
uint32_t      cyclePumpTotalMs = 0;
bool          cycleDoorOpened = false;
uint32_t      pumpBurstStart[2] = {0, 0};
uint32_t      pumpBurstMs[2]    = {0, 0};
uint32_t      doorOpenUptime = 0;
uint32_t      doorCloseUptime = 0;
const char*   cycleOutcome = "in_progress";
const char*   cycleRemarks = "";
bool          screeningFailed = false;   // denied because the sensor could not read
bool          doorForcedClose = false;   // closed on the backstop, still occupied

// Set when a cycle ends, cleared once the sensor reads clear. Without it a
// person still standing in range when the door shut was immediately detected
// again, and got confirmed, sprayed twice and passed through on a loop - one
// duplicate entry per lap, for as long as they stood there.
bool          awaitClear = false;

StateRecord cycleStates[MAX_CYCLE_STATES];
uint8_t     cycleStateCount = 0;

// ---------- Queues ----------
String pendingCycles[MAX_PENDING_CYCLES];
size_t pendingCycleCount = 0;

struct DistanceSample { float cm; bool inRange; uint32_t uptime; };
struct TempSample     { float object; float ambient; bool screening; uint32_t uptime; };

DistanceSample distBuf[DISTANCE_BUFFER];
size_t distHead = 0, distCount = 0;

TempSample tempBuf[TEMP_BUFFER];
size_t tempHead = 0, tempCount = 0;

/* ============================================================
 * Hardware
 * ========================================================== */

void setRelay(bool on) {
  if (RELAY_ACTIVE_LOW) {
    digitalWrite(RELAY_PIN, on ? LOW : HIGH);
  } else {
    digitalWrite(RELAY_PIN, on ? HIGH : LOW);
  }
}

/**
 * Lists every address that acknowledges on the I2C bus.
 *
 * Printed when the thermometer is missing, because "nothing answered at all"
 * and "something answered at another address" are different faults with
 * different fixes - power or wiring versus an unexpected part - and the serial
 * log is the only place to tell them apart.
 */
void scanI2C() {
  Serial.println("   I2C scan:");
  uint8_t found = 0;

  for (uint8_t addr = 1; addr < 127; addr++) {
    Wire.beginTransmission(addr);
    if (Wire.endTransmission() == 0) {
      Serial.print("     device at 0x");
      if (addr < 16) Serial.print('0');
      Serial.print(addr, HEX);
      if (addr == 0x5A) Serial.print("   <- MLX90614 (expected address)");
      Serial.println();
      found++;
    }
  }

  if (found == 0) {
    Serial.print("     nothing answered. The bus is dead, so this is power or wiring, ");
    Serial.println("not the sensor's configuration:");
    Serial.print("       - SDA on GPIO"); Serial.print(I2C_SDA);
    Serial.print(", SCL on GPIO");        Serial.print(I2C_SCL);
    Serial.println(" - and not swapped");
    Serial.println("       - VIN on the rail the breakout expects (a GY-906 wants 5V; a bare");
    Serial.println("         MLX90614 is a 3V part and 5V will damage it)");
    Serial.println("       - GND shared with the ESP32");
    Serial.println("       - 4.7k pull-ups to 3.3V on SDA and SCL if the breakout has none");
  }
}

/** Re-attempts the thermometer. Safe to call repeatedly; begin() re-opens I2C. */
bool mlxTryBegin() {
  return mlx.begin();
}

float readDistanceCM() {
  digitalWrite(TRIG_PIN, LOW);
  delayMicroseconds(2);
  digitalWrite(TRIG_PIN, HIGH);
  delayMicroseconds(10);
  digitalWrite(TRIG_PIN, LOW);

  long duration = pulseIn(ECHO_PIN, HIGH, ULTRASONIC_TIMEOUT_US);
  if (duration == 0) return -1;

  return duration * 0.0343 / 2.0;
}

/* ============================================================
 * Buffers
 * ========================================================== */

void pushDistance(float cm, bool inRange, uint32_t uptime) {
  if (distCount == DISTANCE_BUFFER) {
    // Full: drop the oldest sample. Recent readings matter more than a
    // complete history, and the server is not the system of record for these.
    distHead = (distHead + 1) % DISTANCE_BUFFER;
    distCount--;
    droppedSamples++;
  }
  size_t at = (distHead + distCount) % DISTANCE_BUFFER;
  distBuf[at] = { cm, inRange, uptime };
  distCount++;
}

/**
 * `screening` marks the single reading the access decision was made on, as
 * opposed to the 2-second logging stream. The server stores it as is_screening
 * so the decision is never reconstructed from a nearby sample.
 */
void pushTemp(float object, float ambient, bool screening, uint32_t uptime) {
  if (tempCount == TEMP_BUFFER) {
    tempHead = (tempHead + 1) % TEMP_BUFFER;
    tempCount--;
    droppedSamples++;
  }
  size_t at = (tempHead + tempCount) % TEMP_BUFFER;
  tempBuf[at] = { object, ambient, screening, uptime };
  tempCount++;
}

/* ============================================================
 * JSON assembly
 * ========================================================== */

const char* stateName(SystemState s) {
  switch (s) {
    case IDLE:              return "IDLE";
    case CONFIRMING:        return "CONFIRMING";
    case PUMP_ON_1:         return "PUMP_ON_1";
    case PUMP_OFF_INTERVAL: return "PUMP_OFF_INTERVAL";
    case PUMP_ON_2:         return "PUMP_ON_2";
    case DOOR_OPENING:      return "DOOR_OPENING";
    case DOOR_HOLD_OPEN:    return "DOOR_HOLD_OPEN";
    case DOOR_CLOSING:      return "DOOR_CLOSING";
    case ACCESS_DENIED:     return "ACCESS_DENIED";
  }
  return "IDLE";
}

/**
 * A failed MLX read is NaN and a pulseIn timeout is -1. Both mean "no reading",
 * which must reach the server as null - never as 0.0, which would look like a
 * real measurement of zero.
 */
void appendNumber(String& out, float value, unsigned int decimals) {
  if (isnan(value) || isinf(value)) {
    out += "null";
  } else {
    out += String(value, decimals);
  }
}

void appendDistance(String& out, float cm, unsigned int decimals) {
  if (cm <= 0) {
    out += "null";
  } else {
    appendNumber(out, cm, decimals);
  }
}

String jsonEscape(const char* text) {
  String out;
  for (const char* p = text; *p; p++) {
    if (*p == '"' || *p == '\\') { out += '\\'; out += *p; }
    else if (*p == '\n')         { out += "\\n"; }
    else if ((uint8_t) *p < 0x20) { /* drop control characters */ }
    else                         { out += *p; }
  }
  return out;
}

/**
 * Builds the id that scopes every uptime_ms this run reports. It only has to be
 * unique per boot, not globally, so esp_random() is plenty.
 */
String makeBootId() {
  char buf[37];
  snprintf(buf, sizeof(buf), "%08x-%04x-4%03x-%04x-%08x%04x",
           (unsigned) esp_random(),
           (unsigned) (esp_random() & 0xFFFF),
           (unsigned) (esp_random() & 0x0FFF),
           (unsigned) ((esp_random() & 0x3FFF) | 0x8000),
           (unsigned) esp_random(),
           (unsigned) (esp_random() & 0xFFFF));
  return String(buf);
}

/**
 * The compile-time constants, named exactly as booth_config's columns.
 */
String buildConfigJson() {
  String c;
  c.reserve(560);
  c += "{\"trig_pin\":"   + String(TRIG_PIN);
  c += ",\"echo_pin\":"   + String(ECHO_PIN);
  c += ",\"relay_pin\":"  + String(RELAY_PIN);
  c += ",\"servo_pin\":"  + String(SERVO_PIN);
  c += ",\"i2c_sda_pin\":" + String(I2C_SDA);
  c += ",\"i2c_scl_pin\":" + String(I2C_SCL);
  c += ",\"relay_active_low\":" + String(RELAY_ACTIVE_LOW ? 1 : 0);
  c += ",\"door_closed_angle\":" + String(DOOR_CLOSED_ANGLE);
  c += ",\"door_open_angle\":"   + String(DOOR_OPEN_ANGLE);
  c += ",\"servo_min_us\":" + String(SERVO_MIN_US);
  c += ",\"servo_max_us\":" + String(SERVO_MAX_US);
  c += ",\"detection_distance_cm\":" + String(detectionDistanceCm, 1);
  c += ",\"ultrasonic_timeout_us\":" + String(ULTRASONIC_TIMEOUT_US);
  c += ",\"fever_threshold_c\":" + String(feverThresholdC, 2);
  c += ",\"presence_confirm_time_ms\":" + String(presenceConfirmTime);
  c += ",\"presence_glitch_grace_ms\":" + String(PRESENCE_GLITCH_GRACE);
  c += ",\"sample_interval_ms\":" + String(SAMPLE_INTERVAL);
  c += ",\"print_interval_ms\":"  + String(PRINT_INTERVAL);
  c += ",\"pump_on_time_ms\":"    + String(pumpOnTime);
  c += ",\"pump_off_time_ms\":"   + String(pumpOffTime);
  c += ",\"door_open_time_ms\":"  + String(doorOpenTime);
  c += ",\"temp_print_interval_ms\":" + String(TEMP_PRINT_INTERVAL);
  c += "}";
  return c;
}

String buildCycleJson(uint32_t endedUptime) {
  String c;
  c.reserve(896);

  // cycle_ref is the server's idempotency key: resending this cycle updates the
  // same row instead of recording a second person.
  c += "{\"cycle_ref\":\"" + bootId + ":" + String(cycleDetectedUptime) + "\"";
  c += ",\"detected_uptime_ms\":" + String(cycleDetectedUptime);
  c += ",\"duration_ms\":" + String(endedUptime - cycleDetectedUptime);
  c += ",\"trigger_distance_cm\":"; appendDistance(c, cycleTriggerDistance, 1);
  c += ",\"screening_temp_c\":";    appendNumber(c, cycleScreeningTemp, 2);
  c += ",\"ambient_temp_c\":";      appendNumber(c, cycleAmbientTemp, 2);
  c += ",\"threshold_c\":" + String(cycleThreshold, 2);
  c += ",\"outcome\":\"" + String(cycleOutcome) + "\"";
  c += ",\"pump_bursts\":" + String(cyclePumpBursts);
  c += ",\"pump_total_ms\":" + String(cyclePumpTotalMs);
  c += ",\"door_opened\":" + String(cycleDoorOpened ? "true" : "false");
  c += ",\"remarks\":\"" + jsonEscape(cycleRemarks) + "\"";

  c += ",\"states\":[";
  for (uint8_t i = 0; i < cycleStateCount; i++) {
    if (i) c += ',';
    c += "{\"from_state\":\"" + String(stateName(cycleStates[i].from)) + "\"";
    c += ",\"to_state\":\""   + String(stateName(cycleStates[i].to))   + "\"";
    c += ",\"uptime_ms\":"    + String(cycleStates[i].uptime) + "}";
  }
  c += "]";

  c += ",\"pump\":[";
  for (uint8_t b = 0; b < cyclePumpBursts && b < 2; b++) {
    if (b) c += ',';
    c += "{\"burst_no\":" + String(b + 1) + ",\"duration_ms\":" + String(pumpBurstMs[b]) + "}";
  }
  c += "]";

  c += ",\"door\":[";
  if (cycleDoorOpened) {
    c += "{\"action\":\"open\",\"angle\":" + String(DOOR_OPEN_ANGLE) +
         ",\"uptime_ms\":" + String(doorOpenUptime) + "}";
    if (doorCloseUptime) {
      c += ",{\"action\":\"close\",\"angle\":" + String(DOOR_CLOSED_ANGLE) +
           ",\"uptime_ms\":" + String(doorCloseUptime) + "}";
    }
  }
  c += "]";

  c += "}";
  return c;
}

String buildPayload() {
  String p;
  p.reserve(4096);

  p += "{\"device_id\":\"" + String(DEVICE_ID) + "\"";
  p += ",\"device_name\":\"" + String(DEVICE_NAME) + "\"";
  p += ",\"boot_id\":\"" + bootId + "\"";
  p += ",\"firmware\":\"" + String(FIRMWARE) + "\"";
  p += ",\"uptime_ms\":" + String(millis());
  p += ",\"state\":\"" + String(stateName(currentState)) + "\"";

  if (!bootReported) {
    p += ",\"boot\":{\"mlx_ok\":";
    p += mlxOk ? "true" : "false";
    p += ",\"message\":\"Smart Disinfectant ready.\"}";
  }

  if (configDirty) {
    p += ",\"config\":" + buildConfigJson();
  }

  if (pendingCycleCount) {
    p += ",\"cycles\":[";
    for (size_t i = 0; i < pendingCycleCount; i++) {
      if (i) p += ',';
      p += pendingCycles[i];
    }
    p += "]";
  }

  if (distCount) {
    p += ",\"distance\":[";
    for (size_t i = 0; i < distCount; i++) {
      const DistanceSample& s = distBuf[(distHead + i) % DISTANCE_BUFFER];
      if (i) p += ',';
      p += "{\"distance_cm\":"; appendDistance(p, s.cm, 1);
      p += ",\"in_range\":";    p += s.inRange ? "true" : "false";
      p += ",\"uptime_ms\":" + String(s.uptime) + "}";
    }
    p += "]";
  }

  if (tempCount) {
    p += ",\"temperature\":[";
    for (size_t i = 0; i < tempCount; i++) {
      const TempSample& s = tempBuf[(tempHead + i) % TEMP_BUFFER];
      if (i) p += ',';
      p += "{\"object_temp_c\":";  appendNumber(p, s.object, 2);
      p += ",\"ambient_temp_c\":"; appendNumber(p, s.ambient, 2);
      p += ",\"is_screening\":";   p += s.screening ? "true" : "false";
      p += ",\"uptime_ms\":" + String(s.uptime) + "}";
    }
    p += "]";
  }

  p += "}";
  return p;
}

/* ============================================================
 * Adopting server settings
 * ========================================================== */

/**
 * Pulls one numeric field out of the response. The server's reply is small and
 * flat, so scanning for the key is enough - no parser needed.
 */
bool jsonNumber(const String& src, const char* key, float& out) {
  String needle = String("\"") + key + "\":";
  int at = src.indexOf(needle);
  if (at < 0) return false;

  at += needle.length();
  while (at < (int) src.length() && src[at] == ' ') at++;

  int end = at;
  while (end < (int) src.length()) {
    char ch = src[end];
    if ((ch >= '0' && ch <= '9') || ch == '-' || ch == '+' || ch == '.' || ch == 'e' || ch == 'E') end++;
    else break;
  }
  if (end == at) return false;

  out = src.substring(at, end).toFloat();
  return true;
}

/**
 * Applies one setting if the server's value is inside a range that cannot make
 * the booth unsafe. A bad value is ignored rather than clamped: silently
 * running at the nearest legal bound would hide a misconfigured server.
 */
bool adoptFloat(const String& body, const char* key, float& target, float lo, float hi) {
  float v;
  if (!jsonNumber(body, key, v)) return false;
  if (v < lo || v > hi) {
    Serial.print("!! Ignoring out-of-range ");
    Serial.print(key);
    Serial.print(" = ");
    Serial.println(v);
    return false;
  }
  if (fabs(target - v) < 0.001) return false;
  target = v;
  return true;
}

bool adoptMillis(const String& body, const char* key, unsigned long& target, float lo, float hi) {
  float v = (float) target;
  if (!adoptFloat(body, key, v, lo, hi)) return false;
  target = (unsigned long) v;
  return true;
}

void adoptDirectives(const String& body) {
  bool changed = false;

  // The fever threshold is the safety-critical one: nothing outside a
  // plausible human range is accepted, whatever the server says.
  changed |= adoptFloat(body, "fever_threshold_c", feverThresholdC, 30.0, 45.0);
  changed |= adoptFloat(body, "detection_distance_cm", detectionDistanceCm, 2.0, 400.0);
  changed |= adoptMillis(body, "presence_confirm_time_ms", presenceConfirmTime, 1000, 60000);
  changed |= adoptMillis(body, "pump_on_time_ms", pumpOnTime, 200, 60000);
  changed |= adoptMillis(body, "pump_off_time_ms", pumpOffTime, 0, 60000);
  changed |= adoptMillis(body, "door_open_time_ms", doorOpenTime, 500, 120000);

  if (changed) {
    configDirty = true;   // report the values now in force on the next sync
    Serial.print(">> Adopted server settings: threshold ");
    Serial.print(feverThresholdC, 1);
    Serial.print("C, range ");
    Serial.print(detectionDistanceCm, 0);
    Serial.print("cm, spray ");
    Serial.print(pumpOnTime);
    Serial.print("/");
    Serial.print(pumpOffTime);
    Serial.print("ms, door ");
    Serial.print(doorOpenTime);
    Serial.println("ms");
  }
}

/* ============================================================
 * Sync
 * ========================================================== */

void wifiEnsure(unsigned long now) {
  static unsigned long lastAttempt = 0;
  static bool announced = false;

  if (WiFi.status() == WL_CONNECTED) {
    if (!announced) {
      announced = true;
      Serial.print(">> WiFi connected. IP ");
      Serial.println(WiFi.localIP());

      // mDNS needs an interface that is actually up, so it starts here rather
      // than in setup().
      if (!mdnsStarted) {
        mdnsStarted = MDNS.begin("disinfentry-booth");
        if (!mdnsStarted) Serial.println("!! mDNS failed to start; falling back to the subnet sweep.");
      }

      // Reconnected somewhere else: an address from the old network is worse
      // than none, because something there may well answer on port 80.
      if (serverHost.length() && serverNet != subnetKey()) {
        Serial.println(">> Different network than the server was found on. Searching again.");
        serverHost = "";
        scanOctet = 1;
        discPhase = DISC_REMEMBERED;
      }
    }
    return;
  }

  announced = false;
  if (now - lastAttempt < WIFI_RETRY_INTERVAL) return;
  lastAttempt = now;

  Serial.println("!! WiFi not connected. Retrying...");
  WiFi.disconnect();
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
}

/* ============================================================
 * Finding the server
 *
 * Everything here runs only from syncNow(), which the loop calls only while
 * IDLE with nobody in range - so a probe, like a POST, can never land inside a
 * spray burst or a door hold.
 * ========================================================== */

/** Identifies the network the booth is on, so a cached address from somewhere
 *  else is never trusted after the booth moves. */
uint32_t subnetKey() {
  return ((uint32_t) WiFi.localIP()) & ((uint32_t) WiFi.subnetMask());
}

String serverBase() {
  if (usingFixedServer()) {
    String base = SERVER_URL;
    while (base.endsWith("/")) base.remove(base.length() - 1);   // tolerate a trailing slash
    return base;
  }
  return "http://" + serverHost + ":" + String(SERVER_PORT) + String(SERVER_PATH);
}

/**
 * Asks one address whether it is a DisinfEntry server.
 *
 * The signature matters: plenty of machines answer on port 80, and adopting the
 * first one that does would point the booth at a printer.
 */
bool probeServer(const String& host, uint16_t connectMs, uint16_t readMs) {
  HTTPClient http;
  String url = "http://" + host + ":" + String(SERVER_PORT) + String(SERVER_PATH) + "/api/ping.php";

  if (!http.begin(url)) return false;
  http.setConnectTimeout(connectMs);
  http.setTimeout(readMs);

  int code = http.GET();
  String body = (code == 200) ? http.getString() : String();
  http.end();

  return body.indexOf("\"disinfentry\":true") >= 0;
}

bool adoptServer(const String& host, const char* how) {
  serverHost = host;
  serverNet  = subnetKey();
  connFailures = 0;
  discPhase = DISC_REMEMBERED;

  // Only write when it actually changed. A server that flaps would otherwise
  // rewrite the same two keys every time it came back, for no reason.
  if (prefs.getString("host", "") != host || prefs.getUInt("net", 0) != serverNet) {
    prefs.putString("host", host);
    prefs.putUInt("net", serverNet);
  }

  Serial.print(">> Server found at ");
  Serial.print(host);
  Serial.print(" (");
  Serial.print(how);
  Serial.println("). Remembered for next boot.");
  return true;
}

/**
 * Stops using the current address and searches again.
 *
 * The remembered address in NVS is deliberately KEPT. It is the best guess the
 * booth has, and deleting it meant a transient failure permanently discarded a
 * working address and forced a 254-address sweep to rediscover the same server.
 * Every pass now re-probes it first, patiently, until something better turns up.
 */
void forgetServer() {
  if (usingFixedServer()) return;   // a fixed address is never "lost", only retried
  if (serverHost.length() == 0 || SERVER_PIN_IP[0] != '\0') return;

  Serial.print("!! Server stopped answering at ");
  Serial.print(serverHost);
  Serial.println(". Will retry that address first, then search.");

  serverHost = "";
  scanOctet = 1;
  discPhase = DISC_REMEMBERED;
}

/**
 * Returns true once serverHost holds an address that answered the beacon.
 *
 * Performs at most ONE phase per call and probes only while inside
 * DISCOVERY_BUDGET_MS, so a pass cannot block longer than that plus the probe
 * already in flight - whatever the network looks like.
 */
bool resolveServer() {
  if (usingFixedServer()) return true;   // nothing to search for
  if (serverHost.length()) return true;

  if (SERVER_PIN_IP[0] != '\0') {
    serverHost = SERVER_PIN_IP;
    serverNet  = subnetKey();
    return true;
  }

  unsigned long began = millis();

  /* ---- Phase 1: whatever worked last time ---- */
  if (discPhase == DISC_REMEMBERED) {
    discPhase = DISC_MDNS;   // one attempt, whatever the outcome

    // Only on the network it was found on: an address that means "the server"
    // here can easily mean a printer somewhere else.
    String saved = prefs.getString("host", "");
    if (saved.length() && prefs.getUInt("net", 0) == subnetKey()) {
      // Patient timeouts: this address has answered before, so give it time to
      // answer again rather than writing it off at the sweep's pace.
      if (probeServer(saved, TARGET_CONNECT_MS, TARGET_READ_MS)) {
        return adoptServer(saved, "remembered");
      }
    }
    return false;
  }

  /* ---- Phase 2: mDNS, which survives the address changing ---- */
  if (discPhase == DISC_MDNS) {
    discPhase = DISC_SWEEP;

    if (mdnsStarted) {
      IPAddress found = MDNS.queryHost(SERVER_HOSTNAME, MDNS_QUERY_MS);
      if (((uint32_t) found) != 0
          && probeServer(found.toString(), TARGET_CONNECT_MS, TARGET_READ_MS)) {
        return adoptServer(found.toString(), "mDNS");
      }
    }
    return false;
  }

  /* ---- Phase 3: sweep the subnet ---- */
  // Only a /24 is swept: anything wider is too many addresses to walk, and on a
  // network like that the address should be pinned by hand instead.
  if (((uint32_t) WiFi.subnetMask()) != 0x00FFFFFF) {
    Serial.println("!! Server not found, and the subnet is wider than a /24. Set SERVER_PIN_IP.");
    return false;
  }

  IPAddress self = WiFi.localIP();
  while (millis() - began < DISCOVERY_BUDGET_MS) {
    if (scanOctet < 1 || scanOctet > 254) {
      // A whole lap with nothing found. Start the cycle over at the remembered
      // address - a sweep can miss a host that was simply slow to answer, and
      // re-probing the known-good address patiently is cheaper than another lap.
      scanOctet = 1;
      discPhase = DISC_REMEMBERED;
      Serial.println("!! Swept the subnet without finding the server. Retrying the known address.");
      return false;
    }

    uint8_t target = (uint8_t) scanOctet++;
    if (target == self[3]) continue;   // that one is us

    IPAddress candidate(self[0], self[1], self[2], target);
    if (probeServer(candidate.toString(), PROBE_CONNECT_MS, PROBE_READ_MS)) {
      return adoptServer(candidate.toString(), "subnet sweep");
    }
  }

  Serial.print("... searching for the server (");
  Serial.print(self[0]); Serial.print('.');
  Serial.print(self[1]); Serial.print('.');
  Serial.print(self[2]); Serial.print(".x, reached .");
  Serial.print(scanOctet);
  Serial.println(")");
  return false;
}

bool syncDue(unsigned long now) {
  if (now - lastSyncAttempt < MIN_SYNC_GAP) return false;
  if (lastSyncOk == 0) return true;   // never synced: register as soon as WiFi is up
  if (pendingCycleCount > 0) return true;
  if (distCount >= DISTANCE_BUFFER / 2 || tempCount >= TEMP_BUFFER / 2) return true;
  return (now - lastSyncOk) >= HEARTBEAT_INTERVAL;
}

/**
 * Posts everything queued. Blocking, which is why the caller only runs it while
 * the booth is IDLE with nobody in range.
 *
 * On success the queues are cleared. On failure they are kept and resent: the
 * server keys every row, so a duplicate batch updates rather than doubles up.
 */
bool syncNow() {
  if (WiFi.status() != WL_CONNECTED) return false;

  // Nothing is sent until the server has been located. The queues are keyed and
  // bounded, so waiting here costs a delay, never a record.
  if (!resolveServer()) return false;

  String url = serverBase() + "/api/booth_sync.php";
  String payload = buildPayload();

  // The TLS client must outlive the request, so it lives here, next to the HTTPClient.
  WiFiClientSecure secureClient;
  HTTPClient http;
  const bool tls = usingFixedServer() && serverIsHttps();
  bool started;
  if (tls) {
    configureTls(secureClient);
    started = http.begin(secureClient, url);
  } else {
    started = http.begin(url);
  }
  if (!started) {
    Serial.println(usingFixedServer()
      ? "!! Sync failed: could not build a URL for the server. Check SERVER_URL_VALUE in secrets.h."
      : "!! Sync failed: could not build a URL for the server. Check SERVER_PATH.");
    return false;
  }

  const uint16_t timeoutMs = tls ? HTTPS_TIMEOUT_MS : HTTP_TIMEOUT_MS;
  http.setConnectTimeout(timeoutMs);
  http.setTimeout(timeoutMs);
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-API-Key", API_KEY);

  int code = http.POST(payload);
  bool ok = (code == 200 || code == 201);
  String body = ok ? http.getString() : String();
  http.end();

  if (!ok) {
    Serial.print("!! Sync failed (HTTP ");
    Serial.print(code);
    Serial.print(") at ");
    Serial.print(usingFixedServer() ? String(SERVER_URL) : serverHost);
    Serial.println(". Queued for retry.");

    // A negative code is a connection failure, not a rejection: the server did
    // not answer at all, so the address may have moved. A positive code came
    // from a server that is there and simply unhappy - keep the address and let
    // the retry sort it out.
    if (code < 0 && ++connFailures >= LOST_AFTER_FAILS) forgetServer();
    return false;
  }

  connFailures = 0;

  Serial.print(">> Synced ");
  Serial.print(pendingCycleCount);
  Serial.print(" cycle(s), ");
  Serial.print(distCount + tempCount);
  Serial.print(" reading(s).");
  if (droppedSamples || droppedCycles) {
    // Only ever non-zero after a long outage; worth seeing in the log.
    Serial.print(" Dropped while offline: ");
    Serial.print(droppedCycles);
    Serial.print(" cycle(s), ");
    Serial.print(droppedSamples);
    Serial.print(" reading(s).");
  }
  Serial.println();

  for (size_t i = 0; i < pendingCycleCount; i++) pendingCycles[i] = String();
  pendingCycleCount = 0;
  distHead = distCount = 0;
  tempHead = tempCount = 0;
  bootReported = true;
  configDirty = false;
  lastSyncOk = millis();

  adoptDirectives(body);
  return true;
}

/* ============================================================
 * Cycle bookkeeping
 * ========================================================== */

void recordTransition(SystemState from, SystemState to, uint32_t uptime) {
  if (!cycleActive || cycleStateCount >= MAX_CYCLE_STATES) return;
  cycleStates[cycleStateCount++] = { from, to, uptime };
}

/**
 * Single point of truth for the state machine: assigning currentState anywhere
 * else would leave the transition unrecorded.
 */
void setState(SystemState next, unsigned long now) {
  recordTransition(currentState, next, (uint32_t) now);
  currentState = next;
}

void startCycle(unsigned long now, float distance) {
  cycleActive = true;
  cycleDetectedUptime = (uint32_t) now;
  cycleTriggerDistance = distance;
  cycleScreeningTemp = NAN;
  cycleAmbientTemp = NAN;
  cycleThreshold = feverThresholdC;
  cyclePumpBursts = 0;
  cyclePumpTotalMs = 0;
  cycleDoorOpened = false;
  doorOpenUptime = 0;
  doorCloseUptime = 0;
  cycleStateCount = 0;
  cycleOutcome = "in_progress";
  cycleRemarks = "";
  screeningFailed = false;
  doorForcedClose = false;
}

void finishCycle(unsigned long now, const char* outcome, const char* remarks) {
  if (!cycleActive) return;

  cycleOutcome = outcome;
  cycleRemarks = remarks;
  cycleActive = false;

  // One cycle per visit: the next one waits for the sensor to read clear.
  awaitClear = true;

  if (pendingCycleCount == MAX_PENDING_CYCLES) {
    // Server unreachable for a long stretch. Keep the newest cycles: they are
    // the ones an operator is most likely to be looking for.
    for (size_t i = 1; i < MAX_PENDING_CYCLES; i++) pendingCycles[i - 1] = pendingCycles[i];
    pendingCycleCount--;
    droppedCycles++;
    Serial.println("!! Cycle queue full - dropped the oldest unsent cycle.");
  }

  pendingCycles[pendingCycleCount++] = buildCycleJson((uint32_t) now);
}

/* ============================================================
 * Setup / loop
 * ========================================================== */

void setup() {
  Serial.begin(115200);
  delay(200);

  bootId = makeBootId();

  // Namespace in NVS holding the last server address that answered.
  prefs.begin("disinfentry", false);

  pinMode(TRIG_PIN, OUTPUT);
  pinMode(ECHO_PIN, INPUT);
  pinMode(RELAY_PIN, OUTPUT);
  setRelay(false);

  Wire.begin(I2C_SDA, I2C_SCL);
  mlxOk = mlxTryBegin();
  if (!mlxOk) {
    Serial.println("WARNING: MLX90614 not detected. Screening cannot run, so every");
    Serial.println("         entry will be DENIED until it answers.");
    scanI2C();
    Serial.print("   Retrying every ");
    Serial.print(MLX_RETRY_INTERVAL / 1000);
    Serial.println("s - no reboot needed once the wiring is fixed.");
  }

  ESP32PWM::allocateTimer(0);
  doorServo.setPeriodHertz(50);
  doorServo.attach(SERVO_PIN, SERVO_MIN_US, SERVO_MAX_US);
  doorServo.write(DOOR_CLOSED_ANGLE);

  // Non-blocking: the state machine must start running immediately, with or
  // without a network. Detection and screening never depend on the server.
  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(true);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

  Serial.print("Smart Disinfectant ready. Boot ");
  Serial.println(bootId);
  if (usingFixedServer()) {
    Serial.print("Server: ");
    Serial.print(SERVER_URL);
    Serial.println(serverIsHttps() ? " (fixed address, TLS)" : " (fixed address)");
  } else if (SERVER_PIN_IP[0] != '\0') {
    Serial.print("Server pinned to ");
    Serial.println(SERVER_PIN_IP);
  } else {
    String saved = prefs.getString("host", "");
    Serial.print("Server: ");
    Serial.print(saved.length() ? saved : String("not known yet"));
    Serial.print(" (will confirm, then try mDNS name '");
    Serial.print(SERVER_HOSTNAME);
    Serial.println("', then sweep the subnet)");
  }
  Serial.print("Waiting for object within ");
  Serial.print(detectionDistanceCm, 0);
  Serial.println("cm...");
}

void loop() {
  unsigned long now = millis();

  wifiEnsure(now);

  // ---- Distance sampling ----
  if (now - lastSampleTime >= SAMPLE_INTERVAL) {
    lastSampleTime = now;
    lastDistance = readDistanceCM();
    currentlyInRange = (lastDistance > 0 && lastDistance <= detectionDistanceCm);

    if (now - lastPrintTime >= PRINT_INTERVAL) {
      lastPrintTime = now;
      pushDistance(lastDistance, currentlyInRange, (uint32_t) now);

      Serial.print("[");
      Serial.print(now / 1000.0, 1);
      Serial.print("s] Distance: ");
      if (lastDistance > 0) {
        Serial.print(lastDistance);
        Serial.println(" cm");
      } else {
        Serial.println("out of range");
      }
    }
  }

  // ---- Sync ----
  // Only while idle AND with nobody in range: a POST blocks for up to
  // HTTP_TIMEOUT_MS, which must never land inside a spray burst or a door hold.
  if (currentState == IDLE && !currentlyInRange && syncDue(now)) {
    lastSyncAttempt = now;
    syncNow();

    // The POST consumed real time. Re-read the clock and rebase the sampling
    // timers so they do not all fire at once on the next pass.
    now = millis();
    lastSampleTime = now;
    lastPrintTime = now;
    lastTempPrint = now;
  }

  // ---- Thermometer recovery ----
  // Only while IDLE: begin() re-opens the I2C device, which must not happen
  // underneath a screening read. A booth that heals itself is the difference
  // between a loose wire costing a minute and costing a day.
  if (!mlxOk && currentState == IDLE && now - lastMlxRetry >= MLX_RETRY_INTERVAL) {
    lastMlxRetry = now;
    if (mlxTryBegin()) {
      mlxOk = true;
      bootReported = false;   // resend the boot section so the console clears the fault
      Serial.println(">> MLX90614 is answering again. Screening restored.");
    }
  }

  // ---- Temperature logging ----
  if (now - lastTempPrint >= TEMP_PRINT_INTERVAL) {
    lastTempPrint = now;
    double objTemp = mlx.readObjectTempC();
    double ambTemp = mlx.readAmbientTempC();
    pushTemp((float) objTemp, (float) ambTemp, false, (uint32_t) now);

    Serial.print("[");
    Serial.print(now / 1000.0, 1);
    Serial.print("s] Object Temp: ");
    Serial.print(objTemp);
    Serial.print(" C | Ambient Temp: ");
    Serial.print(ambTemp);
    Serial.println(" C");
  }

  switch (currentState) {

    case IDLE:
      // Whoever just went through has to step out of range before the booth
      // will start another cycle on them.
      if (awaitClear) {
        if (!currentlyInRange) {
          awaitClear = false;
          Serial.println(">> Clear. Ready for the next person.");
        }
        break;
      }

      if (currentlyInRange) {
        motionStartTime = now;
        lastOutOfRangeTime = 0;
        startCycle(now, lastDistance);
        setState(CONFIRMING, now);
        Serial.print(">> Object detected within ");
        Serial.print(detectionDistanceCm, 0);
        Serial.print("cm. Confirming presence for ");
        Serial.print(presenceConfirmTime / 1000.0, 1);
        Serial.println("s...");
      }
      break;

    case CONFIRMING:
      if (!currentlyInRange) {
        if (lastOutOfRangeTime == 0) lastOutOfRangeTime = now;
        if (now - lastOutOfRangeTime >= PRESENCE_GLITCH_GRACE) {
          Serial.println(">> Object left range before confirmation. Resetting.");
          setState(IDLE, now);
          finishCycle(now, "aborted", "Object left range before confirmation");
        }
      } else {
        lastOutOfRangeTime = 0;
        if (now - motionStartTime >= presenceConfirmTime) {
          double objTemp = mlx.readObjectTempC();
          double ambTemp = mlx.readAmbientTempC();
          cycleScreeningTemp = (float) objTemp;
          cycleAmbientTemp   = (float) ambTemp;

          // The reading the decision was actually made on, flagged so it is
          // never confused with a nearby sample from the logging stream.
          pushTemp((float) objTemp, (float) ambTemp, true, (uint32_t) now);

          Serial.print(">> Presence confirmed. Checking temperature: ");
          Serial.print(objTemp);
          Serial.println(" C");

          // Fail closed. A failed MLX read is NaN, and every comparison against
          // NaN is false - so testing only for "too high" would wave everyone
          // through the moment the sensor died. A booth that cannot screen must
          // not grant entry.
          if (isnan(objTemp) || isinf(objTemp)) {
            screeningFailed = true;
            Serial.println(">> ACCESS DENIED: Temperature reading failed. Check the MLX90614.");
            setState(ACCESS_DENIED, now);
          } else if (objTemp >= feverThresholdC) {
            screeningFailed = false;
            Serial.println(">> ACCESS DENIED: Temperature too high. Pump and door blocked.");
            setState(ACCESS_DENIED, now);
          } else {
            screeningFailed = false;
            Serial.println(">> Temperature normal. Starting pump sequence.");
            setRelay(true);
            pumpBurstStart[0] = (uint32_t) now;
            stateStartTime = now;
            setState(PUMP_ON_1, now);
          }
        }
      }
      break;

    case ACCESS_DENIED:
      if (!currentlyInRange) {
        Serial.println(">> Person left. Resetting system.");
        setState(IDLE, now);
        finishCycle(now, "denied",
                    screeningFailed ? "Temperature reading failed (MLX90614 fault)"
                                    : "Temperature too high");
      }
      break;

    case PUMP_ON_1:
      if (now - stateStartTime >= pumpOnTime) {
        setRelay(false);
        pumpBurstMs[0] = (uint32_t) now - pumpBurstStart[0];
        cyclePumpTotalMs += pumpBurstMs[0];
        cyclePumpBursts = 1;
        stateStartTime = now;
        setState(PUMP_OFF_INTERVAL, now);
        Serial.println("Pump OFF (interval).");
      }
      break;

    case PUMP_OFF_INTERVAL:
      if (now - stateStartTime >= pumpOffTime) {
        setRelay(true);
        pumpBurstStart[1] = (uint32_t) now;
        stateStartTime = now;
        setState(PUMP_ON_2, now);
        Serial.println("Pump ON (2nd burst).");
      }
      break;

    case PUMP_ON_2:
      if (now - stateStartTime >= pumpOnTime) {
        setRelay(false);
        pumpBurstMs[1] = (uint32_t) now - pumpBurstStart[1];
        cyclePumpTotalMs += pumpBurstMs[1];
        cyclePumpBursts = 2;

        Serial.println("Pump sequence complete. Opening door.");
        doorServo.write(DOOR_OPEN_ANGLE);
        cycleDoorOpened = true;
        doorOpenUptime = (uint32_t) now;

        stateStartTime = now;
        setState(DOOR_OPENING, now);
      }
      break;

    case DOOR_OPENING:
      if (now - stateStartTime >= 500) {
        stateStartTime = now;
        setState(DOOR_HOLD_OPEN, now);
      }
      break;

    case DOOR_HOLD_OPEN: {
      // Never close on somebody still in the doorway. door_open_time_ms is
      // settable from System Settings down to 500 ms, which on a fixed timer
      // shut a servo-driven door on whoever was walking through it.
      bool minimumHeld = (now - stateStartTime) >= doorOpenTime;
      bool waitedLong  = (now - stateStartTime) >= (doorOpenTime + DOOR_CLEAR_GRACE);

      if ((minimumHeld && !currentlyInRange) || waitedLong) {
        doorForcedClose = waitedLong && currentlyInRange;
        Serial.println(doorForcedClose
                       ? "Closing door (still occupied after the grace period)."
                       : "Closing door.");
        doorServo.write(DOOR_CLOSED_ANGLE);
        doorCloseUptime = (uint32_t) now;
        stateStartTime = now;
        setState(DOOR_CLOSING, now);
      }
      break;
    }

    case DOOR_CLOSING:
      if (now - stateStartTime >= 500) {
        Serial.println("Cycle complete. Returning to idle.\n");
        setState(IDLE, now);
        finishCycle(now, "granted",
                    doorForcedClose ? "Cleared for entry (door closed while still occupied)"
                                    : "Cleared for entry");
      }
      break;
  }
}


