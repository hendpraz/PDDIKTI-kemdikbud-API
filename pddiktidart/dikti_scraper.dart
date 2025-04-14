import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'package:http/http.dart' as http;

import 'all_mahasiswa_itb.dart';

Future<String> getIp() async {
  final url = Uri.parse("https://api.ipify.org?format=json");
  final response = await http.get(url);
  if (response.statusCode != 200) {
    throw Exception("Failed to load IP address");
  }
  final data = json.decode(response.body);
  return data['ip'];
}

Future<void> searchMahasiswa(
  String searchQuery,
  Map<String, String> headers,
  StreamController<String> streamController,
) async {
  try {
    final diktiUrl = "aHR0cHM6Ly9hcGktcGRkaWt0aS5rZW1kaWt0aXNhaW50ZWsuZ28uaWQ=";
    final searchUrl =
        utf8.decode(base64.decode(diktiUrl)) + "/pencarian/mhs/" + searchQuery;

    final response = await http.get(Uri.parse(searchUrl), headers: headers);
    if (response.statusCode != 200) {
      throw Exception("Failed to load data");
    }

    if (response.body.isNotEmpty) {
      final mahasiswaData = json.decode(response.body);
      if (mahasiswaData is List) {
        for (var mahasiswa in mahasiswaData) {
          final mahasiswaId = mahasiswa['id'];
          if (mahasiswaId != null) {
            getMhsDetail(mahasiswaId, headers, streamController);
          }
        }
      }
    }
  } catch (e) {
    print("Error: $e");
  }
}

Future<void> getMhsDetail(
  String mahasiswaId,
  Map<String, String> headers,
  StreamController<String> streamController,
) async {
  try {
    final diktiUrl = "aHR0cHM6Ly9hcGktcGRkaWt0aS5rZW1kaWt0aXNhaW50ZWsuZ28uaWQ=";
    final detailMahasiswaUrl =
        utf8.decode(base64.decode(diktiUrl)) + "/detail/mhs/" + mahasiswaId;

    final response =
        await http.get(Uri.parse(detailMahasiswaUrl), headers: headers);
    if (response.statusCode != 200) {
      throw Exception("Failed to load data");
    }

    streamController.add(response.body);
  } catch (e) {
    print("Error: $e");
  }
}

void main() async {
  try {
    final host = "YXBpLXBkZGlrdGkua2VtZGlrdGlzYWludGVrLmdvLmlk";
    final origin = "aHR0cHM6Ly9wZGRpa3RpLmtlbWRpa3Rpc2FpbnRlay5nby5pZA==";
    final referer = "aHR0cHM6Ly9wZGRpa3RpLmtlbWRpa3Rpc2FpbnRlay5nby5pZC8=";

    final streamController = StreamController<String>();
    streamController.stream.listen((data) {
      print("$data");

      // Write to file
      final file = File("pddiktidart/mahasiswa_itb.txt");
      file.writeAsStringSync(data, mode: FileMode.append);
    });

    final ip = await getIp();

    // String UTF-8

    final headers = {
      "Accept": "application/json, text/plain, */*",
      "Accept-Encoding": "gzip, deflate, br, zstd",
      "Accept-Language": "en-US,en;q=0.9,mt;q=0.8",
      "Connection": "keep-alive",
      "DNT": "1",
      "Host": utf8.decode(base64.decode(host)),
      "Origin": utf8.decode(base64.decode(origin)),
      "Referer": utf8.decode(base64.decode(referer)),
      "Sec-Fetch-Dest": "empty",
      "Sec-Fetch-Mode": "cors",
      "Sec-Fetch-Site": "same-site",
      "User-Agent":
          "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0",
      "X-User-IP": ip,
      "sec-ch-ua":
          '"Microsoft Edge";v="131", "Chromium";v="131", "Not_A Brand";v="24"',
      "sec-ch-ua-mobile": "?0",
      "sec-ch-ua-platform": '"Windows"'
    };

    final queries = mahasiswa;

    for (var query in queries) {
      searchMahasiswa(query, headers, streamController);

      // Delay to avoid overwhelming the server
      // You can adjust the delay time as needed
      // For example, 20 milliseconds
      await Future.delayed(Duration(milliseconds: 20));
    }
  } catch (e) {
    print("Error: $e");
  }
}
