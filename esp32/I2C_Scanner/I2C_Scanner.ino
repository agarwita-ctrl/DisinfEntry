/*
  DisinfEntry - I2C bus scanner

  A bench tool, not part of the booth. Flash it when the booth reports
  "MLX90614 missing at boot" to find out which fault you actually have:

    - nothing answers          -> power or wiring; the bus is dead
    - something at 0x5A        -> the sensor is fine, the booth firmware is not
    - something at another address -> not an MLX90614, or an address conflict

  It needs no WiFi and no server, so it is safe to flash without touching the
  booth's network settings. Re-flash DisinfEntry_Booth afterwards to put the
  booth back in service.

  Same I2C pins as the booth sketch, so it tests the wiring as actually built.
  Scans every 3 seconds and prints only when the result changes - so you can
  wiggle a jumper or reseat a connector and watch the moment it takes.

  Serial monitor: 115200 baud.
*/

#include <Wire.h>
#include <Adafruit_MLX90614.h>

const int I2C_SDA = 21;   // must match DisinfEntry_Booth.ino
const int I2C_SCL = 22;

const uint8_t MLX_ADDR = 0x5A;

Adafruit_MLX90614 mlx = Adafruit_MLX90614();

String lastReport = "";

/** Returns a stable one-line summary of the bus, used to suppress repeats. */
String scanBus(uint8_t& count, bool& mlxPresent) {
  String found = "";
  count = 0;
  mlxPresent = false;

  for (uint8_t addr = 1; addr < 127; addr++) {
    Wire.beginTransmission(addr);
    if (Wire.endTransmission() == 0) {
      if (count) found += ", ";
      found += "0x";
      if (addr < 16) found += "0";
      found += String(addr, HEX);
      if (addr == MLX_ADDR) mlxPresent = true;
      count++;
    }
  }
  return found;
}

/**
 * Reports whether each I2C line is held high by an external pull-up.
 *
 * Run before Wire.begin() takes the pins. The internal pull-down is about 45k;
 * a real bus pull-up is 4.7k-10k and wins easily, so a line that still reads
 * LOW has nothing pulling it up.
 *
 * This separates the two faults an empty scan cannot:
 *   both LOW  -> no pull-ups are present, which on a breakout means it has no
 *                power at all (its pull-ups are fed from VIN) or is not wired
 *   both HIGH -> the bus is alive and idle; something is connected and powered,
 *                so the part itself is not answering
 */
void reportPullups() {
  pinMode(I2C_SDA, INPUT_PULLDOWN);
  pinMode(I2C_SCL, INPUT_PULLDOWN);
  delay(5);

  bool sda = digitalRead(I2C_SDA);
  bool scl = digitalRead(I2C_SCL);

  Serial.print("Idle line levels:  SDA=");
  Serial.print(sda ? "HIGH" : "LOW");
  Serial.print("   SCL=");
  Serial.println(scl ? "HIGH" : "LOW");

  if (!sda && !scl) {
    Serial.println("   Both LOW - nothing is pulling this bus up.");
    Serial.println("   A powered breakout holds both lines HIGH through its own pull-ups,");
    Serial.println("   so this points at NO POWER or NO CONNECTION, not a dead chip:");
    Serial.println("     - is VIN actually connected, and to the rail the board expects?");
    Serial.println("     - is GND connected?");
    Serial.println("     - measure VIN on the sensor board with a multimeter");
  } else if (sda && scl) {
    Serial.println("   Both HIGH - the bus is powered and idle, so the wiring carries");
    Serial.println("   signal and something is pulling up. A scan that still finds");
    Serial.println("   nothing then means the sensor itself is not answering:");
    Serial.println("     - SDA and SCL may be swapped");
    Serial.println("     - the part may be damaged (5V on a bare 3V MLX90614 will do it)");
  } else {
    Serial.println("   One line up, one down - a broken or unseated jumper on the LOW line.");
  }
  Serial.println();
}

void setup() {
  Serial.begin(115200);
  delay(400);   // the MLX90614 needs a moment after power-up before it answers

  Serial.println();
  Serial.println("=== DisinfEntry I2C scanner ===");
  Serial.print("SDA = GPIO"); Serial.print(I2C_SDA);
  Serial.print("   SCL = GPIO"); Serial.println(I2C_SCL);
  Serial.println("Expecting the MLX90614 at 0x5A.");
  Serial.println();

  reportPullups();

  Wire.begin(I2C_SDA, I2C_SCL);
}

void loop() {
  uint8_t count = 0;
  bool mlxPresent = false;
  String found = scanBus(count, mlxPresent);

  String report = String(count) + ":" + found;

  if (report != lastReport) {
    lastReport = report;

    Serial.print("[");
    Serial.print(millis() / 1000.0, 1);
    Serial.print("s] ");

    if (count == 0) {
      Serial.println("NOTHING on the bus.");
      Serial.println("   The bus is dead, so this is power or wiring - not the sensor's");
      Serial.println("   configuration and not the booth firmware. Check, in this order:");
      Serial.println("     1. SDA/GND/SCL jumpers seated, and SDA and SCL not swapped");
      Serial.println("     2. VIN on the right rail. A GY-906 breakout wants 5V; a BARE");
      Serial.println("        MLX90614 is a 3V part and 5V destroys it.");
      Serial.println("     3. GND shared with the ESP32");
      Serial.println("     4. 4.7k pull-ups to 3.3V on SDA and SCL if the breakout has none");
    } else {
      Serial.print(count);
      Serial.print(" device(s): ");
      Serial.println(found);

      if (mlxPresent) {
        Serial.println("   MLX90614 FOUND at 0x5A - the wiring is good.");
        if (mlx.begin(MLX_ADDR, &Wire)) {
          double amb = mlx.readAmbientTempC();
          double obj = mlx.readObjectTempC();
          Serial.print("   ambient "); Serial.print(amb);
          Serial.print(" C | object "); Serial.print(obj); Serial.println(" C");
          if (isnan(amb) || isnan(obj)) {
            Serial.println("   ...but the reads return NaN: the chip answers and then fails,");
            Serial.println("      which points at a marginal supply or a damaged sensor.");
          } else {
            Serial.println("   Sensor is healthy. Re-flash DisinfEntry_Booth.");
          }
        } else {
          Serial.println("   ...but begin() still failed. Marginal supply or a damaged part.");
        }
      } else {
        Serial.println("   No MLX90614 at 0x5A. Something else is on the bus - either the");
        Serial.println("   part is not an MLX90614, or it has a non-default address.");
      }
    }
    Serial.println();
  }

  delay(3000);
}
