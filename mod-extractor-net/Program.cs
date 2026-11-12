using System.Globalization;
using System.Reflection;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;
using System.Text.RegularExpressions;
using CUE4Parse.Encryption.Aes;
using CUE4Parse.FileProvider;
using CUE4Parse.UE4.Assets;
using CUE4Parse.UE4.Assets.Exports;
using CUE4Parse.UE4.Assets.Exports.Engine;
using CUE4Parse.UE4.Localization;
using CUE4Parse.UE4.Readers;
using CUE4Parse.UE4.Assets.Objects;
using CUE4Parse.UE4.Assets.Objects.Properties;
using CUE4Parse.UE4.Objects.Core.i18N;
using CUE4Parse.UE4.Objects.Core.Math;
using CUE4Parse.UE4.Objects.Core.Misc;
using CUE4Parse.UE4.Objects.GameplayTags;
using CUE4Parse.UE4.Objects.UObject;
using CUE4Parse.UE4.Versions;

// Mod class extractor. Dumps every blueprint class of the given mods (or of the given
// base-game packages) with its parent class and the values its class default object
// sets, formatted the way the game's Docs file writes them.
//
// Usage:
//   ModExtractor --install-dir <game install> --out <classes.json>
//                [--mods-file <mods.json>]       [{"reference": "Easy_Recipes", "paksDir": "/x/Content/Paks"}]
//                [--packages-file <packages.json>] ["/Game/FactoryGame/Recipes/Constructor/Recipe_IronPlate"]
//                [--class-names-file <names.json>] ["Desc_IronPlate_C"]
//                [--base-tables <regex>]           base-game data tables to dump, by package name
//                [--engine-candidates GAME_UE5_6,GAME_UE5_5,...] [--aes-key 0x...]

var opts = Args.Parse(args);
string installDir = opts.Require("install-dir");
string outPath = opts.Require("out");
string paksDir = Path.Combine(installDir, "FactoryGame", "Content", "Paks");

EGame[] candidates = (opts.Get("engine-candidates") ?? "GAME_UE5_6,GAME_UE5_5,GAME_UE5_4,GAME_UE5_3")
    .Split(',', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries)
    .Select(s => Enum.TryParse<EGame>(s, out var g) ? (EGame?)g : null)
    .Where(g => g.HasValue).Select(g => g!.Value).Distinct().ToArray();
if (candidates.Length == 0) throw new Exception("No known engine version in --engine-candidates.");
byte[] aesKey = ParseAesKey(opts.Get("aes-key"));

var mods = opts.Get("mods-file") is { } modsFile
    ? JsonSerializer.Deserialize<List<ModInput>>(File.ReadAllText(modsFile), Json.Options) ?? []
    : [];
var basePackages = opts.Get("packages-file") is { } packagesFile
    ? JsonSerializer.Deserialize<List<string>>(File.ReadAllText(packagesFile), Json.Options) ?? []
    : [];
var baseClassNames = opts.Get("class-names-file") is { } classNamesFile
    ? JsonSerializer.Deserialize<List<string>>(File.ReadAllText(classNamesFile), Json.Options) ?? []
    : [];

var main = OpenWithEngine(paksDir, mods.Select(m => m.PaksDir).ToArray(), candidates, aesKey,
    p => basePackages.Select(ToFilePath).FirstOrDefault(p.Files.ContainsKey) is not { } probeFile || p.TryLoadPackage(probeFile, out _), out var mainEngine);
Console.WriteLine($"Engine: {mainEngine} | mounted files: {main.Files.Count}");

var result = new DumpResult(mainEngine.ToString(), [], null);

// Most Docs entries do not say which package they come from, so base classes are also
// looked up by name: a blueprint class X_C lives in a package named X.
if (baseClassNames.Count > 0)
{
    var wanted = baseClassNames.Where(n => n.EndsWith("_C")).Select(n => n[..^2]).ToHashSet(StringComparer.OrdinalIgnoreCase);
    foreach (var key in main.Files.Keys)
    {
        if (!key.StartsWith("FactoryGame/Content/", StringComparison.OrdinalIgnoreCase) || !key.EndsWith(".uasset", StringComparison.OrdinalIgnoreCase)) continue;
        if (wanted.Contains(Path.GetFileNameWithoutExtension(key))) basePackages.Add(NormalizePath(StripExtension(key)));
    }
}

if (opts.Get("base-tables") is { } tablePattern)
{
    var regex = new Regex(tablePattern, RegexOptions.IgnoreCase);
    basePackages.AddRange(main.Files.Keys
        .Where(k => k.StartsWith("FactoryGame/Content/", StringComparison.OrdinalIgnoreCase) && k.EndsWith(".uasset", StringComparison.OrdinalIgnoreCase)
            && regex.IsMatch(Path.GetFileNameWithoutExtension(k)))
        .Select(k => NormalizePath(StripExtension(k))));
}

if (basePackages.Count > 0)
{
    var dump = new ModDump("", mainEngine.ToString(), false, 0, [], [], [], []);
    Exporter.Provider = main;
    foreach (var package in basePackages.Distinct())
        DumpPackage(main, ToFilePath(package), dump);
    result = result with { Base = dump };
    Console.WriteLine($"Base: {dump.Classes.Count} classes, {dump.FailedPackages.Count} failed packages");
}

foreach (var mod in mods)
{
    var own = Open(mod.PaksDir, [], mainEngine, aesKey);
    var files = own.Files.Keys
        .Where(k => k.EndsWith(".uasset", StringComparison.OrdinalIgnoreCase) || k.EndsWith(".umap", StringComparison.OrdinalIgnoreCase))
        .OrderBy(k => k, StringComparer.Ordinal)
        .ToList();
    var overrides = files.Where(k => k.StartsWith("FactoryGame/Content/", StringComparison.OrdinalIgnoreCase))
        .Select(k => NormalizePath(StripExtension(k))).ToList();
    bool legacy = !Directory.EnumerateFiles(mod.PaksDir, "*.utoc", SearchOption.AllDirectories).Any();

    // Mods cooked for an older engine (pre-1.0 legacy .pak files) only parse with that
    // engine's version; their imports are stored by name, so they need no game files.
    IFileProvider provider = main;
    EGame engine = mainEngine;
    var probe = files.FirstOrDefault(k => !k.StartsWith("FactoryGame/Content/", StringComparison.OrdinalIgnoreCase));
    if (probe != null && !Probe(main, probe))
    {
        var fallback = candidates.Where(c => c != mainEngine).Append(mainEngine).ToArray();
        provider = OpenWithEngine(mod.PaksDir, [], fallback, aesKey, p => Probe(p, probe), out engine);
    }

    var dump = new ModDump(mod.Reference, engine.ToString(), legacy, files.Count, [], [], overrides, []);
    Exporter.Provider = provider;
    foreach (var file in files)
        DumpPackage(provider, file, dump);
    result.Mods.Add(dump);
    Console.WriteLine($"{mod.Reference}: {files.Count} packages, {dump.Classes.Count} classes, {dump.FailedPackages.Count} failed (engine {engine})");
}

Directory.CreateDirectory(Path.GetDirectoryName(Path.GetFullPath(outPath))!);
File.WriteAllText(outPath, JsonSerializer.Serialize(result, Json.Options));
Console.WriteLine($"Done → {outPath}");
return 0;

static bool Probe(IFileProvider provider, string file)
{
    try
    {
        return provider.LoadPackage(file).GetExports().Any();
    }
    catch
    {
        return false;
    }
}

static void DumpPackage(IFileProvider provider, string file, ModDump dump)
{
    IPackage package;
    List<UObject> exports;
    try
    {
        package = provider.LoadPackage(file);
        exports = package.GetExports().ToList();
    }
    catch (Exception e)
    {
        dump.FailedPackages.Add(new FailedPackage(NormalizePath(StripExtension(file)), FirstLine(e)));
        return;
    }

    foreach (var table in exports.OfType<UDataTable>())
    {
        var rows = new Dictionary<string, Dictionary<string, object>>();
        foreach (var (rowName, row) in table.RowMap)
            rows[rowName.Text] = Exporter.Properties(row);
        dump.DataTables.Add(new TableDump(NormalizePath(StripExtension(file)) + "." + table.Name, rows));
    }

    foreach (var export in exports.OfType<UClass>())
    {
        try
        {
            dump.Classes.Add(DumpClass(export, NormalizePath(StripExtension(file))));
        }
        catch (Exception e)
        {
            dump.FailedPackages.Add(new FailedPackage(NormalizePath(StripExtension(file)) + "." + export.Name, FirstLine(e)));
        }
    }
}

static ClassDump DumpClass(UClass cls, string packagePath)
{
    string? super = null;
    bool superResolved = true;
    if (cls.SuperStruct is { IsNull: false } superIndex)
    {
        var resolved = superIndex.ResolvedObject;
        if (resolved != null)
            super = NormalizePath(resolved.GetPathName());
        else
            superResolved = false;
    }

    var properties = new Dictionary<string, object>();
    if (cls.ClassDefaultObject is { IsNull: false } cdoIndex && cdoIndex.TryLoad(out var cdo) && cdo != null)
        properties = Exporter.Properties(cdo);

    return new ClassDump(cls.Name, packagePath, $"{cls.ExportType} {packagePath}.{cls.Name}", super, superResolved,
        cls.ClassFlags.HasFlag(EClassFlags.CLASS_Abstract), properties);
}

static DefaultFileProvider OpenWithEngine(string dir, string[] extra, EGame[] engines, byte[] aesKey, Func<DefaultFileProvider, bool> verify, out EGame chosen)
{
    Exception? last = null;
    foreach (var engine in engines)
    {
        try
        {
            var provider = Open(dir, extra, engine, aesKey);
            if (verify(provider))
            {
                chosen = engine;
                return provider;
            }
        }
        catch (Exception e)
        {
            last = e;
        }
    }
    throw new Exception($"No engine version could read {dir}. {last?.Message}");
}

static DefaultFileProvider Open(string dir, string[] extra, EGame engine, byte[] aesKey)
{
    var provider = new DefaultFileProvider(new DirectoryInfo(dir), extra.Select(d => new DirectoryInfo(d)).ToArray(),
        SearchOption.AllDirectories, new VersionContainer(engine), StringComparer.OrdinalIgnoreCase);
    provider.Initialize();
    provider.SubmitKey(new FGuid(), new FAesKey(aesKey));
    return provider;
}

static string ToFilePath(string packagePath)
{
    if (packagePath.StartsWith("/Game/", StringComparison.OrdinalIgnoreCase))
        return "FactoryGame/Content/" + packagePath["/Game/".Length..] + ".uasset";
    return packagePath.TrimStart('/') + ".uasset";
}

static string StripExtension(string file) => Regex.Replace(file, @"\.(uasset|umap)$", "", RegexOptions.IgnoreCase);

static string NormalizePath(string path) => Paths.Normalize(path);

static string FirstLine(Exception e) => e.GetType().Name + ": " + e.Message.Split('\n')[0];

static byte[] ParseAesKey(string? key)
{
    if (string.IsNullOrWhiteSpace(key)) return new byte[32];
    key = key.StartsWith("0x", StringComparison.OrdinalIgnoreCase) ? key[2..] : key;
    return Convert.FromHexString(key);
}

static class Paths
{
    private static readonly Regex ModContent = new(@"^/?FactoryGame/Mods/(?:GameFeatures/)?([^/]+)/Content/", RegexOptions.IgnoreCase);
    private static readonly Regex GameContent = new(@"^/?FactoryGame/Content/", RegexOptions.IgnoreCase);

    // Legacy packages report their own objects by file path; the Docs use mount paths.
    public static string Normalize(string path)
    {
        var mod = ModContent.Match(path);
        if (mod.Success) return "/" + mod.Groups[1].Value + "/" + path[mod.Length..];
        var game = GameContent.Match(path);
        if (game.Success) return "/Game/" + path[game.Length..];
        return path;
    }
}

static class Exporter
{
    private const string ClassClassPath = "/Script/Engine.BlueprintGeneratedClass";

    private static IFileProvider? provider;

    private static Dictionary<string, Dictionary<string, string>>? Localization;

    public static IFileProvider? Provider
    {
        get => provider;
        set
        {
            provider = value;
            Localization = null;
        }
    }

    public static Dictionary<string, object> Properties(AbstractPropertyHolder obj)
    {
        var result = new Dictionary<string, object>();
        foreach (var tag in obj.Properties)
        {
            if (tag.ArrayIndex > 0 || tag.Tag == null) continue;
            var subobjects = Subobjects(tag.Tag);
            if (subobjects != null)
            {
                result[tag.Name.Text] = subobjects;
                continue;
            }
            var text = Value(tag.Tag, true);
            if (text != null) result[tag.Name.Text] = text;
        }
        return result;
    }

    // Instanced subobject arrays (schematic unlocks and dependencies) are written as
    // a list of objects in the Docs, each with its class name and own properties.
    private static List<Dictionary<string, object>>? Subobjects(FPropertyTagType tag)
    {
        if (tag is not ArrayProperty { Value: { } array } || array.Properties.Count == 0) return null;
        var objects = new List<UObject>();
        foreach (var element in array.Properties)
        {
            if (element is not ObjectProperty { Value: { IsExport: true } index }) return null;
            var outer = index.ResolvedObject?.Outer?.Name.Text;
            if (outer == null || !outer.StartsWith("Default__", StringComparison.Ordinal)) return null;
            if (!index.TryLoad(out var loaded) || loaded == null) return null;
            objects.Add(loaded);
        }
        return objects.Select(o =>
        {
            var entry = new Dictionary<string, object> { ["Class"] = o.ExportType };
            foreach (var (key, value) in Properties(o)) entry[key] = value;
            return entry;
        }).ToList();
    }

    public static string? Value(FPropertyTagType tag, bool top)
    {
        switch (tag)
        {
            case BoolProperty b: return b.Value ? "True" : "False";
            case FloatProperty f: return Float(f.Value);
            case DoubleProperty d: return Float(d.Value);
            case IntProperty i: return i.Value.ToString(CultureInfo.InvariantCulture);
            case Int8Property i: return i.Value.ToString(CultureInfo.InvariantCulture);
            case Int16Property i: return i.Value.ToString(CultureInfo.InvariantCulture);
            case Int64Property i: return i.Value.ToString(CultureInfo.InvariantCulture);
            case UInt16Property i: return i.Value.ToString(CultureInfo.InvariantCulture);
            case UInt32Property i: return i.Value.ToString(CultureInfo.InvariantCulture);
            case UInt64Property i: return i.Value.ToString(CultureInfo.InvariantCulture);
            case EnumProperty e: return EnumValue(e.Value.Text);
            case ByteProperty by: return by.GenericValue is FName enumName ? EnumValue(enumName.Text) : Convert.ToString(by.GenericValue, CultureInfo.InvariantCulture);
            case NameProperty n: return top ? n.Value.Text : Quote(n.Value.Text);
            case StrProperty s: return top ? s.Value : Quote(s.Value ?? "");
            case TextProperty t: return top ? Text(t.Value) : Quote(Text(t.Value));
            case SoftObjectProperty so: return SoftPath(so.Value, top);
            case ObjectProperty o: return ObjectRef(o.Value, top);
            case ArrayProperty a: return a.Value == null ? "" : Container(a.Value.Properties);
            case SetProperty s: return s.Value == null ? "" : Container(s.Value.Properties);
            case MapProperty m:
                return m.Value == null || m.Value.Properties.Count == 0
                    ? ""
                    : "(" + string.Join(",", m.Value.Properties.Select(kv => "(" + Value(kv.Key, false) + "," + Value(kv.Value, false) + ")")) + ")";
            case StructProperty st: return st.Value == null ? "()" : Struct(st.Value.StructType);
            default: return null;
        }
    }

    // References that cannot be resolved are left out rather than written as None.
    private static string Container(List<FPropertyTagType> elements)
    {
        var values = elements
            .Where(e => e is not ObjectProperty o || (o.Value != null && !o.Value.IsNull && o.Value.ResolvedObject != null))
            .Select(e => Value(e, false) ?? "")
            .ToList();
        return values.Count == 0 ? "" : "(" + string.Join(",", values) + ")";
    }

    private static string Struct(object? value)
    {
        switch (value)
        {
            case null: return "()";
            case FStructFallback fallback:
                return "(" + string.Join(",", fallback.Properties
                    .Where(p => p.Tag != null && p.ArrayIndex == 0)
                    .Select(p => p.Name.Text + "=" + (Value(p.Tag!, false) ?? ""))) + ")";
            case FLinearColor c: return $"(R={Float(c.R)},G={Float(c.G)},B={Float(c.B)},A={Float(c.A)})";
            case FColor c: return $"(B={c.B},G={c.G},R={c.R},A={c.A})";
            case FGameplayTagContainer tags:
                return tags.GameplayTags.Length == 0
                    ? "()"
                    : "(GameplayTags=(" + string.Join(",", tags.GameplayTags.Select(t => "(TagName=" + Quote(t.TagName.Text) + ")")) + "))";
            case FSoftObjectPath path: return SoftPath(path, false);
            default: return Reflected(value);
        }
    }

    private static string Reflected(object value)
    {
        var parts = new List<string>();
        foreach (var field in value.GetType().GetFields(BindingFlags.Public | BindingFlags.Instance))
        {
            var v = field.GetValue(value);
            parts.Add(field.Name + "=" + v switch
            {
                float f => Float(f),
                double d => Float(d),
                bool b => b ? "True" : "False",
                FName n => n.Text,
                string s => Quote(s),
                null => "None",
                _ when v.GetType().IsPrimitive => Convert.ToString(v, CultureInfo.InvariantCulture),
                _ => Struct(v),
            });
        }
        return "(" + string.Join(",", parts) + ")";
    }

    private static string ObjectRef(FPackageIndex? index, bool top)
    {
        if (index == null || index.IsNull) return "None";
        var resolved = index.ResolvedObject;
        if (resolved == null) return "None";
        var path = Paths.Normalize(resolved.GetPathName());
        var classObject = resolved.Class;
        var className = classObject?.Name.Text ?? "Object";
        var classPath = classObject != null ? Paths.Normalize(classObject.GetPathName()) : "/Script/CoreUObject.Object";
        bool isClass = className is "BlueprintGeneratedClass" or "Class" or "WidgetBlueprintGeneratedClass";
        if (isClass && path.StartsWith("/Script/", StringComparison.Ordinal)) classPath = "/Script/CoreUObject.Class";
        else if (isClass && !classPath.StartsWith("/Script/", StringComparison.Ordinal)) classPath = ClassClassPath;
        if (top && !isClass) return $"{className} {path}";
        var text = $"{classPath}'{path}'";
        return top ? text : Quote(text);
    }

    private static string SoftPath(FSoftObjectPath path, bool top)
    {
        var asset = path.AssetPathName.Text;
        if (string.IsNullOrEmpty(asset) || asset == "None") return top ? "None" : "\"\"";
        var text = Paths.Normalize(asset) + (string.IsNullOrEmpty(path.SubPathString) ? "" : ":" + path.SubPathString);
        return top ? text : Quote(text);
    }

    private static string Text(FText? text)
    {
        if (text?.TextHistory is FTextHistory.StringTableEntry entry && string.IsNullOrEmpty(text.Text))
            return LocalizedString(entry.TableId.Text, entry.Key) ?? "";
        return text?.Text ?? "";
    }

    // Cooked string tables keep only a table + key reference; the English text lives in
    // the en-US .locres files, with the table id as the namespace.
    private static string? LocalizedString(string tableId, string key)
    {
        if (Provider == null) return null;
        if (Localization == null)
        {
            Localization = new Dictionary<string, Dictionary<string, string>>();
            foreach (var file in Provider.Files.Where(f => Regex.IsMatch(f.Key, @"/Localization/[^/]+/en(-US)?/[^/]+\.locres$", RegexOptions.IgnoreCase))
                         .OrderBy(f => f.Key.Contains("/en-US/", StringComparison.OrdinalIgnoreCase) ? 1 : 0))
            {
                try
                {
                    var resource = new FTextLocalizationResource(new FByteArchive(file.Key, file.Value.Read()));
                    foreach (var (ns, entries) in resource.Entries)
                    {
                        if (!Localization.TryGetValue(ns.Str, out var target))
                            Localization[ns.Str] = target = new Dictionary<string, string>();
                        foreach (var (entryKey, value) in entries)
                            target[entryKey.Str] = value.LocalizedString;
                    }
                }
                catch
                {
                    // An unreadable file only leaves its texts empty.
                }
            }
        }
        var name = tableId.Contains('.') ? tableId[(tableId.LastIndexOf('.') + 1)..] : tableId;
        return Localization.TryGetValue(name, out var table) && table.TryGetValue(key, out var text) ? text : null;
    }

    private static string EnumValue(string value)
    {
        var separator = value.LastIndexOf("::", StringComparison.Ordinal);
        return separator >= 0 ? value[(separator + 2)..] : value;
    }

    private static string Float(double value) => value.ToString("F6", CultureInfo.InvariantCulture);

    private static string Quote(string value) => "\"" + value.Replace("\\", "\\\\").Replace("\"", "\\\"") + "\"";
}

sealed record ModInput(
    [property: JsonPropertyName("reference")] string Reference,
    [property: JsonPropertyName("paksDir")] string PaksDir);

sealed record FailedPackage(
    [property: JsonPropertyName("path")] string Path,
    [property: JsonPropertyName("error")] string Error);

sealed record ClassDump(
    [property: JsonPropertyName("className")] string ClassName,
    [property: JsonPropertyName("packagePath")] string PackagePath,
    [property: JsonPropertyName("fullName")] string FullName,
    [property: JsonPropertyName("super")] string? Super,
    [property: JsonPropertyName("superResolved")] bool SuperResolved,
    [property: JsonPropertyName("abstract")] bool Abstract,
    [property: JsonPropertyName("properties")] Dictionary<string, object> Properties);

sealed record ModDump(
    [property: JsonPropertyName("reference")] string Reference,
    [property: JsonPropertyName("engine")] string Engine,
    [property: JsonPropertyName("legacy")] bool Legacy,
    [property: JsonPropertyName("packages")] int Packages,
    [property: JsonPropertyName("classes")] List<ClassDump> Classes,
    [property: JsonPropertyName("failedPackages")] List<FailedPackage> FailedPackages,
    [property: JsonPropertyName("gameOverrides")] List<string> GameOverrides,
    [property: JsonPropertyName("dataTables")] List<TableDump> DataTables);

sealed record TableDump(
    [property: JsonPropertyName("path")] string Path,
    [property: JsonPropertyName("rows")] Dictionary<string, Dictionary<string, object>> Rows);

sealed record DumpResult(
    [property: JsonPropertyName("engine")] string Engine,
    [property: JsonPropertyName("mods")] List<ModDump> Mods,
    [property: JsonPropertyName("base")] ModDump? Base);

static class Json
{
    public static readonly JsonSerializerOptions Options = new()
    {
        WriteIndented = true,
        PropertyNameCaseInsensitive = true,
        Encoder = System.Text.Encodings.Web.JavaScriptEncoder.UnsafeRelaxedJsonEscaping,
    };
}

sealed class Args
{
    private readonly Dictionary<string, string> map;
    private Args(Dictionary<string, string> map) => this.map = map;

    public static Args Parse(string[] args)
    {
        var map = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
        for (int i = 0; i < args.Length; i++)
        {
            if (!args[i].StartsWith("--")) continue;
            string key = args[i][2..];
            string value = i + 1 < args.Length && !args[i + 1].StartsWith("--") ? args[++i] : "true";
            map[key] = value;
        }
        return new Args(map);
    }

    public string? Get(string key) => map.TryGetValue(key, out var v) ? v : null;
    public string Require(string key) => Get(key) ?? throw new ArgumentException($"Missing required --{key}");
}
