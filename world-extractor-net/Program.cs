using System.Text.Json;
using System.Text.Json.Serialization;
using CUE4Parse.Compression;
using CUE4Parse.Encryption.Aes;
using CUE4Parse.FileProvider;
using CUE4Parse.MappingsProvider.Usmap;
using CUE4Parse.UE4.Assets.Exports;
using CUE4Parse.UE4.Objects.Core.Math;
using CUE4Parse.UE4.Objects.Core.Misc;
using CUE4Parse.UE4.Objects.Engine;
using CUE4Parse.UE4.Objects.UObject;
using CUE4Parse.UE4.Versions;

// default-world.json extractor for the world-data-generator microservice.
//
// Reads the resource node actors (nodes, geysers, fracking cores/satellites)
// from the persistent level of a Satisfactory install and writes them in the
// default-world.json format consumed by Konsl/satisfactory-world-generator.
// Adapted from that repo's MIT-licensed scripts/extract.cs + scripts/convert.js.
//
// Usage:
//   WorldExtractor --install-dir <game install> --out <default-world.json>
//                  [--engine-candidates GAME_UE5_6,GAME_UE5_5] [--game-version 1.2.3.1]

var opts = Args.Parse(args);
string installDir = opts.Require("install-dir");
string outPath = opts.Require("out");

string paksDir = Path.Combine(installDir, "FactoryGame", "Content", "Paks");
string usmapPath = Path.Combine(installDir, "CommunityResources", "FactoryGame.usmap");

// --- game + engine version from the loose .version file in the install ---
var (versionFileGameVersion, versionFileEngine) = ReadVersionFile(installDir);
string? gameVersion = opts.Get("game-version") ?? versionFileGameVersion;
if (gameVersion is null) throw new Exception("Could not read game version from install; pass --game-version.");

string engineList = opts.Get("engine-candidates")
    ?? (versionFileEngine is not null ? $"{versionFileEngine}," : "") + "GAME_UE5_6,GAME_UE5_5,GAME_UE5_4";
EGame[] candidates = engineList.Split(',', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries)
    .Select(s => Enum.TryParse<EGame>(s, out var g) ? (EGame?)g : null)
    .Where(g => g.HasValue).Select(g => g!.Value).Distinct().ToArray();

TryInitOodle();

const string levelPath = "FactoryGame/Content/FactoryGame/Map/GameLevel01/Persistent_Level.umap.PersistentLevel";

var (provider, level, engine) = OpenLevel(paksDir, usmapPath, candidates);
Console.WriteLine($"Engine: {engine} | mounted files: {provider.Files.Count} | game version: {gameVersion}");

var resourceNodes = new List<ResourceNode>();
var geysers = new List<GeyserNode>();
var cores = new List<FrackingCore>();
var coresByName = new Dictionary<string, FrackingCore>();
int skipped = 0;

foreach (var actorIndex in level.Actors)
{
    UObject? actor;
    try { actor = actorIndex.Load(); }
    catch { skipped++; continue; }
    if (actor is null) continue;

    switch (actor.ExportType)
    {
        case "BP_ResourceNode_C":
            resourceNodes.Add(new ResourceNode(
                actor.Name, Location(actor), ResourceClass(actor), Purity(actor)));
            break;
        case "BP_ResourceNodeGeyser_C":
            geysers.Add(new GeyserNode(actor.Name, Location(actor), Purity(actor)));
            break;
        case "BP_FrackingCore_C":
        {
            var core = new FrackingCore(actor.Name, Location(actor));
            cores.Add(core);
            coresByName[core.Name] = core;
            break;
        }
        case "BP_FrackingSatellite_C":
        {
            string coreName = actor.Get<FPackageIndex>("mCore").Name;
            string resource = ResourceClass(actor);
            if (!coresByName.TryGetValue(coreName, out var core))
                throw new Exception($"Satellite {actor.Name} references unknown core {coreName} (actor order?)");
            if (core.Resource is not null && core.Resource != resource)
                throw new Exception($"Core {coreName} has satellites with different resources ({core.Resource} vs {resource})");
            core.Resource = resource;
            core.Satellites.Add(new GeyserNode(actor.Name, Location(actor), Purity(actor)));
            break;
        }
    }
}

if (skipped > 0) Console.Error.WriteLine($"WARN: {skipped} level actors failed to load and were skipped.");
if (resourceNodes.Count == 0) throw new Exception("No resource nodes found — extraction failed.");
if (cores.Any(c => c.Resource is null)) throw new Exception("Fracking core without satellites/resource found.");

var world = new World(gameVersion, resourceNodes, geysers, cores);
File.WriteAllText(outPath, JsonSerializer.Serialize(world, Json.Options));
Console.WriteLine(
    $"Done: {resourceNodes.Count} resource nodes, {geysers.Count} geysers, " +
    $"{cores.Count} fracking cores ({cores.Sum(c => c.Satellites.Count)} satellites) -> {outPath}");
return 0;

// ---- helpers ----

static float[] Location(UObject actor)
{
    var loc = actor.Get<FPackageIndex>("mBoxComponent").Load()!.Get<FVector>("RelativeLocation");
    return [loc.X, loc.Y, loc.Z];
}

static string ResourceClass(UObject actor) => actor.Get<FPackageIndex>("mResourceClass").Name;

static string Purity(UObject actor) => actor.GetOrDefault<FName>("mPurity", new FName("RP_Normal")).ToString();

static (string?, string?) ReadVersionFile(string installDir)
{
    var dir = Path.Combine(installDir, "Engine", "Binaries", "Win64");
    if (!Directory.Exists(dir)) return (null, null);
    foreach (var file in Directory.EnumerateFiles(dir, "*-Win64-Shipping.version")
                 .OrderByDescending(f => f.Contains("FactoryGame")))
    {
        try
        {
            var doc = JsonDocument.Parse(File.ReadAllText(file));
            string? version = doc.RootElement.TryGetProperty("GameVersion", out var v) ? v.GetString() : null;
            string? engine = doc.RootElement.TryGetProperty("MajorVersion", out var maj)
                             && doc.RootElement.TryGetProperty("MinorVersion", out var min)
                ? $"GAME_UE{maj.GetInt32()}_{min.GetInt32()}"
                : null;
            if (version is not null) return (version, engine);
        }
        catch { /* try next candidate */ }
    }
    return (null, null);
}

static void TryInitOodle()
{
    string lib = Path.Combine(AppContext.BaseDirectory, OodleHelper.OODLE_DLL_NAME);
    try
    {
        if (!File.Exists(lib)) OodleHelper.DownloadOodleDll(lib);
        if (File.Exists(lib)) OodleHelper.Initialize(lib);
    }
    catch (Exception e)
    {
        Console.Error.WriteLine($"WARN: oodle unavailable ({e.Message}) — continuing without it.");
    }
}

static (IFileProvider, ULevel, EGame) OpenLevel(string paksDir, string usmapPath, EGame[] candidates)
{
    FileUsmapTypeMappingsProvider? mappings = null;
    if (File.Exists(usmapPath))
    {
        try { mappings = new FileUsmapTypeMappingsProvider(usmapPath); }
        catch (Exception e)
        {
            Console.Error.WriteLine($"WARN: usmap unparseable ({e.GetType().Name}) — continuing without mappings.");
        }
    }

    Exception? last = null;
    foreach (var game in candidates)
    {
        try
        {
            var provider = new DefaultFileProvider(
                paksDir, SearchOption.AllDirectories, new VersionContainer(game),
                StringComparer.OrdinalIgnoreCase);
            if (mappings != null) provider.MappingsContainer = mappings;
            provider.Initialize();
            provider.SubmitKey(new FGuid(), new FAesKey(new byte[32]));
            var level = provider.LoadPackageObject<ULevel>(levelPath);
            if (level.Actors.Length == 0) throw new Exception("level has no actors");
            return (provider, level, game);
        }
        catch (Exception e)
        {
            last = e;
        }
    }

    throw new Exception($"No candidate engine version could load the level. Last error: {last}");
}

// ---- output model (matches Konsl/satisfactory-world-generator default-world.json) ----

record ResourceNode(
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("location")] float[] Location,
    [property: JsonPropertyName("resource")] string Resource,
    [property: JsonPropertyName("purity")] string Purity);

record GeyserNode(
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("location")] float[] Location,
    [property: JsonPropertyName("purity")] string Purity);

class FrackingCore(string name, float[] location)
{
    [JsonPropertyName("name")] public string Name { get; } = name;
    [JsonPropertyName("location")] public float[] Location { get; } = location;
    [JsonPropertyName("resource")] public string? Resource { get; set; }
    [JsonPropertyName("satellites")] public List<GeyserNode> Satellites { get; } = [];
}

record World(
    [property: JsonPropertyName("gameVersion")] string GameVersion,
    [property: JsonPropertyName("resourceNodes")] List<ResourceNode> ResourceNodes,
    [property: JsonPropertyName("geysers")] List<GeyserNode> Geysers,
    [property: JsonPropertyName("frackingCores")] List<FrackingCore> FrackingCores);

static class Json
{
    public static readonly JsonSerializerOptions Options = new()
    {
        DefaultIgnoreCondition = JsonIgnoreCondition.Never,
    };
}

class Args(Dictionary<string, string> values)
{
    public static Args Parse(string[] args)
    {
        var values = new Dictionary<string, string>();
        for (int i = 0; i + 1 < args.Length; i += 2)
        {
            if (!args[i].StartsWith("--")) throw new Exception($"Unexpected argument '{args[i]}'");
            values[args[i][2..]] = args[i + 1];
        }
        return new Args(values);
    }

    public string? Get(string name) => values.GetValueOrDefault(name);
    public string Require(string name) => Get(name) ?? throw new Exception($"Missing required --{name}");
}
