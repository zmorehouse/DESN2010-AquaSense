#include <SPI.h>
#include <WiFi.h>

const char* ssid = "";   // Your WiFi SSID
const char* password = "";               // Your WiFi password

const char* host = "zmdesign.work";               // Your WordPress site domain or IP address
const int port = 80;                               // HTTP port (default is 80)

const int applianceNumber = 1;                     // Define your appliance number here

void setup() {
  Serial.begin(115200);
  delay(100);

  // Connect to WiFi
  Serial.println();
  Serial.println();
  Serial.print("Connecting to ");
  Serial.println(ssid);
  WiFi.begin(ssid, password);
  while (WiFi.status() != WL_CONNECTED) {
    delay(500);
    Serial.print(".");
  }
  Serial.println("");
  Serial.println("WiFi connected");
}

void loop() {
  // Generate a random number between 1 and 10
  int randomNumber = random(1, 15);

  // Create the URL for the HTTP POST request
  String url = "/wp-admin/admin-ajax.php?action=receive_data_from_arduino"; // Replace this with the endpoint on your WordPress site
  String postData = "value=" + String(randomNumber) + "&appliance=" + String(applianceNumber); // Data to be sent

  // Make HTTP POST request
  WiFiClient client;
  if (client.connect(host, port)) {
    Serial.println("Connected to server");
    client.println("POST " + url + " HTTP/1.1");
    client.println("Host: " + String(host));
    client.println("Content-Type: application/x-www-form-urlencoded");
    client.print("Content-Length: ");
    client.println(postData.length());
    client.println();
    client.print(postData);
    client.println();
    delay(1000); // Wait for server response

    // Read server response
    while (client.available()) {
      Serial.write(client.read());
    }

    client.stop();
    Serial.println("Data sent: value=" + String(randomNumber) + "&appliance=" + String(applianceNumber));
  } else {
    Serial.println("Connection failed");
  }

  // Wait for 10 seconds before sending the next random number
  delay(10000);
}
